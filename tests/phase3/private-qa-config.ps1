[CmdletBinding()]
param()

# Configuration-only proof for the private real-data QA boundary. It creates
# only ignored, synthetic env files, an empty ignored staging directory and a
# minimal ignored selection manifest. It never starts a container, creates a
# Docker resource or reads a media source.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runner = Join-Path $projectRoot 'infra\scripts\private-qa.ps1'
$secretAcl = Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$run = [Guid]::NewGuid().ToString('N')
$work = Join-Path $projectRoot ('.codex-work\private-real-qa\config-test-' + $run)
$staging = Join-Path $work 'staging'
$selectionDirectory = Join-Path $work 'selection'
$selectionManifest = Join-Path $selectionDirectory 'private-selection-manifest.json'
$piwigoEnv = Join-Path $work '.env.piwigo'
$immichEnv = Join-Path $work '.env.immich'
$negativeVolumeEnv = Join-Path $work '.env.synthetic-volume'
$negativeSourceEnv = Join-Path $work '.env.source'
$script:assertions = 0

. $secretAcl

function Assert-True([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw $Code }
}

function New-TestSecret {
    $bytes = [byte[]]::new(36)
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }
    return [Convert]::ToBase64String($bytes).Replace('+', '_').Replace('/', '-').TrimEnd('=')
}

function Write-PrivateFile([string]$Path, [string]$Contents) {
    [IO.File]::WriteAllText($Path, $Contents, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Invoke-Runner([string]$Piwigo, [string]$Immich) {
    $output = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner validate -PiwigoEnvPath $Piwigo -ImmichEnvPath $Immich 2>&1)
    return @{
        ExitCode = $LASTEXITCODE
        Lines = @($output | ForEach-Object { [string]$_ })
    }
}

function Invoke-ComposeJson([string[]]$Arguments) {
    $output = @(& $wsl -d Ubuntu --cd $projectRoot -- @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'compose_config_failed' }
    return ([string]::Join("`n", $output) | ConvertFrom-Json -ErrorAction Stop)
}

function Property([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    return $(if ($null -eq $property) { $null } else { $property.Value })
}

try {
    New-Item -ItemType Directory -Path $staging -Force | Out-Null
    New-Item -ItemType Directory -Path $selectionDirectory -Force | Out-Null
    Write-PrivateFile $selectionManifest "{}`n"
    $stagingWsl = @(& $wsl -d Ubuntu --exec wslpath -a $staging 2>&1)
    Assert-True ($LASTEXITCODE -eq 0 -and $stagingWsl.Count -eq 1) 'staging_wsl_path_failed'
    $selectionWsl = @(& $wsl -d Ubuntu --exec wslpath -a $selectionManifest 2>&1)
    Assert-True ($LASTEXITCODE -eq 0 -and $selectionWsl.Count -eq 1) 'selection_wsl_path_failed'

    $piwigoText = @"
COMPOSE_PROJECT_NAME=class_archive_private_qa_piwigo
CLASS_ARCHIVE_HTTP_PORT=8190
CLASS_ARCHIVE_COMPAT_HTTP_PORT=8191
CLASS_ARCHIVE_GATEWAY_NETWORK=class_archive_private_qa_gateway
CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:8190
CLASS_ARCHIVE_TIMEZONE=Asia/Shanghai
PRIVATE_QA_STAGING_PATH=$($stagingWsl[0])
PRIVATE_QA_SELECTION_MANIFEST_PATH=$($selectionWsl[0])
PIWIGO_UID=1000
PIWIGO_GID=1000
PIWIGO_DATA_VOLUME=class_archive_private_qa_piwigo_data
PIWIGO_UPLOADS_VOLUME=class_archive_private_qa_piwigo_uploads
PIWIGO_GALLERIES_VOLUME=class_archive_private_qa_piwigo_galleries
PIWIGO_DERIVATIVES_VOLUME=class_archive_private_qa_piwigo_derivatives
PIWIGO_DB_VOLUME=class_archive_private_qa_piwigo_db
PIWIGO_SCRIPTS_VOLUME=class_archive_private_qa_piwigo_scripts
PIWIGO_BACKUPS_VOLUME=class_archive_private_qa_piwigo_backups
PIWIGO_IMAGE=piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84
MARIADB_IMAGE=mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf
DB_NAME=piwigo
DB_USER=piwigo
DB_PASSWORD=$(New-TestSecret)
DB_ROOT_PASSWORD=$(New-TestSecret)
PIWIGO_ADMIN_USERNAME=private-qa-admin
PIWIGO_ADMIN_EMAIL=admin@private-qa.invalid
CLASS_ARCHIVE_CLAIM_CODE_PEPPER=$(New-TestSecret)
CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=$(New-TestSecret)
SMTP_HOST=
SMTP_PORT=
SMTP_USERNAME=
SMTP_PASSWORD=
SMTP_ENCRYPTION=
"@
    $immichText = @"
IMMICH_COMPOSE_PROJECT_NAME=class_archive_private_qa_immich
CLASS_ARCHIVE_COMPAT_HTTP_PORT=8191
CLASS_ARCHIVE_CORE_PUBLIC_PORT=8190
CLASS_ARCHIVE_GATEWAY_NETWORK=class_archive_private_qa_gateway
IMMICH_UPLOAD_VOLUME=class_archive_private_qa_immich_upload
IMMICH_MODEL_CACHE_VOLUME=class_archive_private_qa_immich_model_cache
IMMICH_DB_VOLUME=class_archive_private_qa_immich_db
IMMICH_GATEWAY_SECRET_VOLUME=class_archive_private_qa_immich_gateway_secret
PIWIGO_UPLOADS_VOLUME=class_archive_private_qa_piwigo_uploads
PIWIGO_GALLERIES_VOLUME=class_archive_private_qa_piwigo_galleries
DB_PASSWORD=$(New-TestSecret)
DB_USERNAME=postgres
DB_DATABASE_NAME=immich
TZ=Asia/Shanghai
"@
    Write-PrivateFile $piwigoEnv $piwigoText
    Write-PrivateFile $immichEnv $immichText
    $piwigoEnvWsl = @(& $wsl -d Ubuntu --exec wslpath -a $piwigoEnv 2>&1)
    Assert-True ($LASTEXITCODE -eq 0 -and $piwigoEnvWsl.Count -eq 1) 'piwigo_env_wsl_path_failed'
    $immichEnvWsl = @(& $wsl -d Ubuntu --exec wslpath -a $immichEnv 2>&1)
    Assert-True ($LASTEXITCODE -eq 0 -and $immichEnvWsl.Count -eq 1) 'immich_env_wsl_path_failed'

    $positive = Invoke-Runner $piwigoEnv $immichEnv
    if ($positive.ExitCode -ne 0) {
        $safeFailure = @($positive.Lines | Where-Object { $_ -match '^PRIVATE_QA=FAIL code=[A-Za-z0-9_.-]{1,96}$' } | Select-Object -First 1)
        if ($safeFailure.Count -eq 1) {
            throw ('positive_' + $safeFailure[0].Substring('PRIVATE_QA=FAIL code='.Length))
        }
        throw 'private_config_positive_failed'
    }
    Assert-True $true 'private_config_positive_passed'
    Assert-True ($positive.Lines -contains 'PRIVATE_QA=PASS action=validate evidence=CONFIG_VALIDATED') 'private_config_pass_marker_missing'

    $syntheticText = $piwigoText.Replace('PIWIGO_DATA_VOLUME=class_archive_private_qa_piwigo_data', 'PIWIGO_DATA_VOLUME=class_archive_piwigo_data')
    Write-PrivateFile $negativeVolumeEnv $syntheticText
    $negativeVolume = Invoke-Runner $negativeVolumeEnv $immichEnv
    Assert-True ($negativeVolume.ExitCode -eq 2) 'synthetic_volume_not_rejected'
    Assert-True ($negativeVolume.Lines -contains 'PRIVATE_QA=FAIL code=private_volume_identity_invalid') 'synthetic_volume_reason_invalid'

    Write-PrivateFile $negativeSourceEnv ($piwigoText + "`nPRIVATE_QA_SOURCE_PATH=/forbidden/source`n")
    $negativeSource = Invoke-Runner $negativeSourceEnv $immichEnv
    Assert-True ($negativeSource.ExitCode -eq 2) 'source_configuration_not_rejected'
    if ($negativeSource.Lines -notcontains 'PRIVATE_QA=FAIL code=source_configuration_forbidden') {
        $safeFailure = @($negativeSource.Lines | Where-Object { $_ -match '^PRIVATE_QA=FAIL code=[A-Za-z0-9_.-]{1,96}$' } | Select-Object -First 1)
        if ($safeFailure.Count -eq 1) {
            throw ('source_' + $safeFailure[0].Substring('PRIVATE_QA=FAIL code='.Length))
        }
        throw 'source_configuration_reason_invalid'
    }
    Assert-True $true 'source_configuration_reason_valid'

    $defaultPiwigo = Invoke-ComposeJson @(
        'docker', 'compose', '--env-file', '.env.example',
        '-f', 'infra/docker-compose.yml', 'config', '--format', 'json'
    )
    Assert-True ([string](Property $defaultPiwigo 'name') -eq 'class_archive_piwigo') 'default_piwigo_project_changed'
    $defaultPiwigoService = Property (Property $defaultPiwigo 'services') 'piwigo'
    $defaultPorts = @((Property $defaultPiwigoService 'ports'))
    Assert-True ($defaultPorts.Count -eq 2) 'default_port_count_changed'
    $defaultPublished = @($defaultPorts | ForEach-Object { [string](Property $_ 'published') } | Sort-Object)
    Assert-True (($defaultPublished -join ',') -eq '8090,8091') 'default_ports_changed'
    Assert-True ([string](Property (Property (Property $defaultPiwigo 'networks') 'immich_gateway') 'name') -eq 'class_archive_immich_gateway') 'default_gateway_changed'
    $defaultGatewayIpam = @((Property (Property (Property $defaultPiwigo 'networks') 'immich_gateway') 'ipam'))
    Assert-True (($defaultGatewayIpam | ConvertTo-Json -Compress -Depth 5) -match '172\.23\.0\.0/16') 'default_gateway_subnet_changed'

    $privatePiwigo = Invoke-ComposeJson @(
        'docker', 'compose', '--env-file', $piwigoEnvWsl[0],
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-qa/docker-compose.override.yml',
        'config', '--format', 'json'
    )
    $privateGatewayIpam = @((Property (Property (Property $privatePiwigo 'networks') 'immich_gateway') 'ipam'))
    Assert-True (($privateGatewayIpam | ConvertTo-Json -Compress -Depth 5) -match '172\.27\.0\.0/16') 'private_gateway_subnet_changed'

    $defaultImmich = Invoke-ComposeJson @(
        'docker', 'compose', '--env-file', 'infra/immich-spike/.env.example',
        '-f', 'infra/immich-spike/docker-compose.yml',
        '--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-web-compat',
        '--profile', 'immich-gateway-integration',
        'config', '--format', 'json'
    )
    Assert-True ([string](Property $defaultImmich 'name') -eq 'class-archive-immich-spike') 'default_immich_project_changed'
    $defaultImmichVolumes = Property $defaultImmich 'volumes'
    Assert-True ([string](Property (Property $defaultImmichVolumes 'immich_upload') 'name') -eq 'class_archive_immich_spike_upload') 'default_upload_volume_changed'
    Assert-True ([string](Property (Property $defaultImmichVolumes 'immich_model_cache') 'name') -eq 'class_archive_immich_spike_model_cache') 'default_model_volume_changed'
    Assert-True ([string](Property (Property $defaultImmichVolumes 'immich_db') 'name') -eq 'class_archive_immich_spike_db') 'default_db_volume_changed'
    Assert-True ([string](Property (Property $defaultImmichVolumes 'immich_gateway_secret') 'name') -eq 'class_archive_immich_gateway_secret') 'default_secret_volume_changed'
    Assert-True ([string](Property (Property (Property $defaultImmich 'networks') 'class_archive_gateway') 'name') -eq 'class_archive_immich_gateway') 'default_immich_gateway_changed'
    $defaultWeb = Property (Property $defaultImmich 'services') 'immich-web-compat'
    Assert-True ([string](Property (Property $defaultWeb 'environment') 'CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT') -eq '8091') 'default_compat_port_changed'
    Assert-True ([string](Property (Property (Property $defaultWeb 'networks') 'class_archive_gateway') 'ipv4_address') -eq '172.23.0.10') 'default_compat_gateway_address_changed'
    $privateImmich = Invoke-ComposeJson @(
        'docker', 'compose', '--env-file', $immichEnvWsl[0],
        '-f', 'infra/immich-spike/docker-compose.yml', '-f', 'infra/private-qa/docker-compose.immich.override.yml',
        '--profile', 'immich-web-compat', 'config', '--format', 'json'
    )
    $privateWeb = Property (Property $privateImmich 'services') 'immich-web-compat'
    Assert-True ([string](Property (Property (Property $privateWeb 'networks') 'class_archive_gateway') 'ipv4_address') -eq '172.27.0.10') 'private_compat_gateway_address_changed'
    $nginxConfig = [IO.File]::ReadAllText((Join-Path $projectRoot 'infra\piwigo-nginx\nginx.conf'))
    Assert-True ($nginxConfig.Contains('proxy_set_header Host $http_host;')) 'compat_proxy_did_not_preserve_loopback_port'
    Assert-True (-not $nginxConfig.Contains('proxy_set_header Host "127.0.0.1:8091";')) 'compat_proxy_still_hardcodes_default_port'
    Assert-True ($nginxConfig.Contains('set_real_ip_from 172.23.0.10/32;')) 'canonical_gateway_trust_missing'
    Assert-True ($nginxConfig.Contains('set_real_ip_from 172.27.0.10/32;')) 'private_gateway_trust_missing'

    Write-Output "PRIVATE_QA_CONFIG_TEST=PASS assertions=$script:assertions evidence=CONFIG_ONLY"
    exit 0
} catch {
    $reason = [string]$_.Exception.Message
    if ($reason -notmatch '^[A-Za-z0-9_.-]{1,96}$') { $reason = 'config_test_exception' }
    Write-Output "PRIVATE_QA_CONFIG_TEST=FAIL code=$reason"
    exit 2
} finally {
    if (Test-Path -LiteralPath $work) {
        Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
    }
}
