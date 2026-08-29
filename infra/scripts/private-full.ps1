[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'validate-owner', 'config', 'ps', 'runtime-staging', 'runtime-owner', 'up-staging', 'stop-staging', 'backup-owner', 'cutover-preflight', 'cutover', 'rollback')]
    [string]$Action = 'validate',

    # A private-full business backup briefly stops only the owner Piwigo
    # writer. Requiring the named switch makes that short, observable write
    # interruption intentional and prevents an ambiguous lifecycle invocation
    # from selecting 8191 by accident.
    [switch]$ConfirmOwnerPrivateBackup,

    [string]$PiwigoStagingEnvPath,
    [string]$ImmichStagingEnvPath,
    [string]$PiwigoOwnerEnvPath,
    [string]$ImmichOwnerEnvPath,
    [string]$CutoverApprovalPath
)

# Blue/green lifecycle guard for the complete private local library. It does
# not accept an original-source path and never runs docker down/prune/rm. The
# only host bind it accepts is a verified opaque, read-only ingress staging
# directory; writable media is always in Docker-managed POSIX volumes. The
# existing private sample-QA stack is stopped, never deleted, during cutover.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
# Lifecycle commands are consumed by scripts as well as by an owner terminal.
# Suppress Invoke-WebRequest's progress UI so PASS/FAIL records remain a clean,
# parseable one-line protocol in Windows PowerShell 5.1.
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateDirectory = Join-Path $projectRoot 'infra\private-full'
$storageRunner = Join-Path $PSScriptRoot 'private-full-storage.ps1'
$piwigoCompose = 'infra/docker-compose.yml'
$piwigoOverride = 'infra/private-full/docker-compose.override.yml'
$immichCompose = 'infra/immich-spike/docker-compose.yml'
$immichOverride = 'infra/private-full/docker-compose.immich.override.yml'
$piwigoProject = 'class_archive_private_full_v3_piwigo'
$immichProject = 'class_archive_private_full_v3_immich'
$gatewayNetwork = 'class_archive_private_full_v3_gateway'
$gatewaySubnet = '10.241.0.0/16'
$gatewayCompatAddress = '10.241.0.10'
$legacyRunner = Join-Path $PSScriptRoot 'private-qa.ps1'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$boundedNativeHelper = Join-Path $PSScriptRoot 'class-archive-bounded-native-process.ps1'
$script:stage = 'initialization'
$script:managedStorageMode = ''
$script:legacyStopped = $false
$script:ownerStarted = $false

if ([string]::IsNullOrWhiteSpace($PiwigoStagingEnvPath)) { $PiwigoStagingEnvPath = Join-Path $privateDirectory '.env.piwigo.staging' }
if ([string]::IsNullOrWhiteSpace($ImmichStagingEnvPath)) { $ImmichStagingEnvPath = Join-Path $privateDirectory '.env.immich.staging' }
if ([string]::IsNullOrWhiteSpace($PiwigoOwnerEnvPath)) { $PiwigoOwnerEnvPath = Join-Path $privateDirectory '.env.piwigo.owner' }
if ([string]::IsNullOrWhiteSpace($ImmichOwnerEnvPath)) { $ImmichOwnerEnvPath = Join-Path $privateDirectory '.env.immich.owner' }
if ([string]::IsNullOrWhiteSpace($CutoverApprovalPath)) { $CutoverApprovalPath = Join-Path $projectRoot '.codex-work\private-real-full\reports\cutover-approval.json' }

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-PrivateFull([string]$Code) {
    # `exit` from a helper invoked inside the lifecycle try/catch becomes a
    # generic RuntimeException in Windows PowerShell 5.1 and hides the actual
    # safety gate. Carry a narrow, non-sensitive code to the outer handler.
    throw [InvalidOperationException]::new('PRIVATE_FULL_STOP:' + $Code)
}
if (-not (Test-Path -LiteralPath $boundedNativeHelper -PathType Leaf)) { Stop-PrivateFull 'bounded_native_helper_missing' }
. $boundedNativeHelper

function Set-PrivateFullUtf8ConsoleEncoding {
    # Docker Compose configuration is emitted by WSL as UTF-8 JSON. Windows
    # PowerShell can otherwise decode a non-ASCII checkout path using the
    # legacy console code page, causing a false source-mount mismatch even
    # when the exact bind mount is read-only. Set the process-local native
    # command encoding before any WSL/Compose call; inability to establish
    # this boundary is a validation failure, never a reason to relax mount
    # comparisons.
    try {
        $utf8 = [Text.UTF8Encoding]::new($false)
        [Console]::OutputEncoding = $utf8
        $script:OutputEncoding = $utf8
        if ([Console]::OutputEncoding.CodePage -ne 65001) { Stop-PrivateFull 'utf8_console_encoding_unavailable' }
    }
    catch {
        Stop-PrivateFull 'utf8_console_encoding_unavailable'
    }
}

Set-PrivateFullUtf8ConsoleEncoding

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-PrivateFull 'config_must_be_inside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredUntrackedFile([string]$Path, [string]$Label) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-PrivateFull ($Label + '_path_untrusted')
    }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateFull ($Label + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -gt 0) { Stop-PrivateFull ($Label + '_is_tracked') }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
    return $item.FullName
}

function Read-StrictEnvironment([string]$Path, [string[]]$Allowed, [string]$Label) {
    $bytes = [IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -lt 16 -or $bytes.Length -gt 65536) { Stop-PrivateFull ($Label + '_size_invalid') }
    try { $text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes) } catch { Stop-PrivateFull ($Label + '_encoding_invalid') }
    if ($text.Contains("`0")) { Stop-PrivateFull ($Label + '_encoding_invalid') }
    $allowedSet = @{}
    foreach ($name in $Allowed) { $allowedSet[$name] = $true }
    $values = @{}
    foreach ($line in ($text -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-PrivateFull ($Label + '_line_invalid') }
        $name = [string]$Matches[1]
        $value = [string]$Matches[2]
        # A full import can read only the managed staging copy. Configuration
        # names that could lead to an original/NAS/host-source mount are never
        # valid, even if a future compose file happens to accept them.
        if ($name -match '(?:SOURCE|NAS|ORIGINAL|REAL_MEDIA|HOST_MOUNT)') { Stop-PrivateFull 'source_configuration_forbidden' }
        if (-not $allowedSet.ContainsKey($name) -or $values.ContainsKey($name)) { Stop-PrivateFull ($Label + '_key_invalid') }
        if ($value.Contains("`r") -or $value.Contains("`n") -or $value.Contains("`0")) { Stop-PrivateFull ($Label + '_value_invalid') }
        $values[$name] = $value
    }
    return $values
}

function Require-Value([hashtable]$Values, [string]$Name, [string]$Code) {
    if (-not $Values.ContainsKey($Name) -or [string]::IsNullOrWhiteSpace([string]$Values[$Name])) { Stop-PrivateFull ($Code + '_missing') }
    return [string]$Values[$Name]
}

function Assert-ExactValue([hashtable]$Values, [string]$Name, [string]$Expected, [string]$Code) {
    if (-not [string]::Equals((Require-Value $Values $Name $Code), $Expected, [StringComparison]::Ordinal)) { Stop-PrivateFull $Code }
}

function Assert-Secret([hashtable]$Values, [string]$Name, [string]$Code) {
    $value = Require-Value $Values $Name $Code
    if ($value.Length -lt 32 -or $value.Length -gt 190 -or $value -notmatch '^[A-Za-z0-9_-]+$' -or $value -match '^__.*__$') { Stop-PrivateFull $Code }
}

function Assert-Volume([hashtable]$Values, [string]$Name, [string]$Expected) {
    Assert-ExactValue $Values $Name $Expected 'private_full_volume_identity_invalid'
    if ($Expected -notmatch '^class_archive_private_full_[a-z0-9_]+$') { Stop-PrivateFull 'private_full_volume_identity_invalid' }
}

function Get-WslPath([string]$Path) {
    # Keep the inverse conversion in-process for the same Unicode reason as
    # Get-WindowsPath below. The lifecycle accepts only ordinary local drive
    # paths; UNC, traversal and alternate separators are not valid private
    # runtime inputs.
    try { $full = [IO.Path]::GetFullPath($Path) } catch { Stop-PrivateFull 'wsl_path_conversion_failed' }
    if ($full -notmatch '^([a-zA-Z]):\\(.+)$') { Stop-PrivateFull 'wsl_path_conversion_failed' }
    $drive = $Matches[1].ToLowerInvariant()
    $segments = @($Matches[2] -split '\\')
    if ($segments.Count -lt 1 -or @($segments | Where-Object {
            [string]::IsNullOrWhiteSpace($_) -or $_ -eq '.' -or $_ -eq '..' -or $_ -match '[/\x00:]'
        }).Count -ne 0) {
        Stop-PrivateFull 'wsl_path_conversion_failed'
    }
    return '/mnt/' + $drive + '/' + ($segments -join '/')
}

function Get-WindowsPath([string]$Path) {
    # `wslpath -w` writes its result through the WSL console boundary. On a
    # Windows checkout whose path contains non-ASCII characters, that output
    # can be decoded differently from the UTF-8 value read from the private
    # environment file. The result is a false "manifest missing" failure
    # before any runtime or media state is touched. These call sites accept
    # only canonical DrvFS paths, so convert that narrow form in-process and
    # reject traversal or Windows path separators instead of relying on a
    # locale-sensitive round trip.
    if ($Path -notmatch '^/mnt/([a-zA-Z])/(.+)$') { Stop-PrivateFull 'wsl_path_conversion_failed' }
    $drive = $Matches[1].ToUpperInvariant()
    $segments = @($Matches[2].Split('/'))
    if ($segments.Count -lt 1 -or @($segments | Where-Object {
            [string]::IsNullOrWhiteSpace($_) -or $_ -eq '.' -or $_ -eq '..' -or $_ -match '[\\\x00:]'
        }).Count -ne 0) {
        Stop-PrivateFull 'wsl_path_conversion_failed'
    }
    $candidate = $drive + ':\' + ($segments -join '\')
    try { $full = [IO.Path]::GetFullPath($candidate) } catch { Stop-PrivateFull 'wsl_path_conversion_failed' }
    if (-not $full.StartsWith($drive + ':\', [StringComparison]::OrdinalIgnoreCase)) { Stop-PrivateFull 'wsl_path_conversion_failed' }
    return $full
}

function Invoke-PrivateFullWsl([string[]]$Arguments, [string]$FailureCode, [ValidateRange(1,900)][int]$TimeoutSeconds = 120, [switch]$Capture) {
    if ($FailureCode -notmatch '^[a-z0-9_]{3,112}$') { Stop-PrivateFull 'native_failure_code_invalid' }
    try {
        $boundedArguments = Add-ClassArchiveWslTimeout -Arguments $Arguments -TimeoutSeconds $TimeoutSeconds
        $result = Invoke-ClassArchiveBoundedNative -Executable $wsl -Arguments $boundedArguments -TimeoutSeconds ($TimeoutSeconds + 15) -WorkingDirectory $projectRoot
    }
    catch { Stop-PrivateFull ($FailureCode + '_start_failed') }
    if ($result.TimedOut) { Stop-PrivateFull ($FailureCode + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-PrivateFull $FailureCode }
    $lines = @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { [string]$_ } | Where-Object { $_ -ne '' })
    if ($Capture) { return $lines }
    return @()
}

function Invoke-PrivateFullDocker([string[]]$DockerArguments, [string]$FailureCode, [ValidateRange(1,900)][int]$TimeoutSeconds = 60, [switch]$Capture) {
    return Invoke-PrivateFullWsl (@('-d','Ubuntu','--exec','docker') + $DockerArguments) $FailureCode $TimeoutSeconds -Capture:$Capture
}

function Invoke-PrivateFullChildPowerShell([string]$ScriptPath, [string[]]$Arguments, [string]$FailureCode, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    if (-not (Test-Path -LiteralPath $ScriptPath -PathType Leaf)) { Stop-PrivateFull ($FailureCode + '_script_missing') }
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    try {
        $result = Invoke-ClassArchiveBoundedNative -Executable $powershell -Arguments (@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$ScriptPath) + $Arguments) -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $projectRoot
    }
    catch { Stop-PrivateFull ($FailureCode + '_start_failed') }
    if ($result.TimedOut) { Stop-PrivateFull ($FailureCode + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-PrivateFull $FailureCode }
    return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { [string]$_ })
}

function Invoke-PrivateFullStorage([string]$StorageAction) {
    if (-not (Test-Path -LiteralPath $storageRunner -PathType Leaf)) { Stop-PrivateFull 'storage_runner_missing' }
    return Invoke-PrivateFullChildPowerShell $storageRunner @($StorageAction) ('storage_' + $StorageAction + '_failed') 180
}

function Assert-DockerManagedStorage([hashtable]$Values) {
    $mode = Require-Value $Values 'PRIVATE_FULL_MANAGED_STORAGE_MODE' 'managed_storage_mode'
    if ($mode -ne 'DOCKER_MANAGED_POSIX_VOLUMES') { Stop-PrivateFull 'managed_storage_mode_invalid' }
    $lines = Invoke-PrivateFullStorage 'status'
    if (@($lines | Where-Object { $_ -match '^PRIVATE_FULL_STORAGE=PASS action=status mode=DOCKER_MANAGED_POSIX_VOLUMES payload=DOCKER_VOLUME_POSIX_PROBED at_rest_owner_acl=DOCKER_DESKTOP_LOCAL_ONLY ' }).Count -ne 1) {
        Stop-PrivateFull 'managed_posix_storage_status_invalid'
    }
    $script:managedStorageMode = $mode
}

function Assert-PrivateManifest([string]$Configured) {
    if ($Configured -notmatch '^/mnt/c/') { Stop-PrivateFull 'import_manifest_path_invalid' }
    $windows = Get-WindowsPath $Configured
    $full = Assert-IgnoredUntrackedFile $windows 'import_manifest'
    $expectedRoot = Join-Path $projectRoot '.codex-work\private-real-full\manifests'
    $prefix = [IO.Path]::GetFullPath($expectedRoot).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not [IO.Path]::GetFullPath($full).StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase) -or
        -not [IO.Path]::GetFileName($full).Equals('full-real-import-manifest.json', [StringComparison]::Ordinal)) {
        Stop-PrivateFull 'import_manifest_path_invalid'
    }
    if (-not [string]::Equals($Configured, (Get-WslPath $full), [StringComparison]::Ordinal)) { Stop-PrivateFull 'import_manifest_path_not_canonical' }
    return $Configured
}

function Get-PrivateManifestDigest([string]$Configured) {
    $windows = Get-WindowsPath $Configured
    try { $record = Get-Content -LiteralPath $windows -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-PrivateFull 'import_manifest_invalid' }
    $digest = [string]$record.import_digest
    if ($digest -notmatch '^[a-f0-9]{64}$' -or @($record.items).Count -lt 1) { Stop-PrivateFull 'import_manifest_invalid' }
    return $digest
}

function Assert-OpaqueStagingDirectory([string]$Configured, [string]$ManifestDigest) {
    if ($Configured -notmatch '^/mnt/[a-z]/') { Stop-PrivateFull 'staging_path_invalid' }
    $windows = Get-WindowsPath $Configured
    $item = Get-Item -LiteralPath $windows -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-PrivateFull 'staging_path_untrusted' }
    if (-not [string]::Equals($Configured, (Get-WslPath $item.FullName), [StringComparison]::Ordinal)) { Stop-PrivateFull 'staging_path_not_canonical' }
    $marker = Join-Path $item.FullName '.classarchive-private-full-staging-v1.json'
    if (-not (Test-Path -LiteralPath $marker -PathType Leaf)) { Stop-PrivateFull 'staging_marker_missing' }
    try { $record = Get-Content -LiteralPath $marker -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-PrivateFull 'staging_marker_invalid' }
    if ([string]$record.version -ne '1' -or [string]$record.layout -ne 'OPAQUE_HASHED_FILES' -or
        -not [string]::Equals([string]$record.import_digest, $ManifestDigest, [StringComparison]::Ordinal)) {
        Stop-PrivateFull 'staging_marker_invalid'
    }
    $names = @(Get-ChildItem -LiteralPath $item.FullName -File -Force | Where-Object { $_.Name -ne '.classarchive-private-full-staging-v1.json' } | Select-Object -ExpandProperty Name)
    if ($names.Count -lt 1 -or @($names | Where-Object { $_ -notmatch '^frl-[a-f0-9]{64}\.(jpg|jpeg|png|webp)$' }).Count -ne 0) {
        Stop-PrivateFull 'staging_layout_invalid'
    }
    return $Configured
}

function Assert-PrivateExtensionCache([string]$Configured) {
    $expectedWindows = Join-Path $projectRoot '.codex-work\private-real-full\runtime\official-extension-cache'
    $expectedWsl = Get-WslPath $expectedWindows
    if (-not [string]::Equals($Configured, $expectedWsl, [StringComparison]::Ordinal)) { Stop-PrivateFull 'extension_cache_path_invalid' }
    $item = Get-Item -LiteralPath $expectedWindows -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { Stop-PrivateFull 'extension_cache_path_untrusted' }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-PrivateFull 'extension_cache_not_ignored' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
    return $Configured
}

function Get-PropertyValue([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Invoke-ComposeJson([string[]]$Arguments) {
    $output = @(Invoke-PrivateFullWsl (@('-d','Ubuntu','--cd',$projectRoot,'--') + $Arguments) 'compose_config_failed' 90 -Capture)
    if ($output.Count -lt 1) { Stop-PrivateFull 'compose_config_failed' }
    try { return ([string]::Join("`n", $output) | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-PrivateFull 'compose_config_invalid' }
}

function Invoke-Compose([string[]]$Prefix, [string[]]$Command) {
    $arguments = $Prefix + $Command
    [void](Invoke-PrivateFullWsl (@('-d','Ubuntu','--cd',$projectRoot,'--') + $arguments) 'compose_command_failed' 180)
}

function Get-EndpointSpec([string]$Mode) {
    if ($Mode -eq 'staging') { return @{ http = '8290'; compat = '8291'; base = 'http://127.0.0.1:8290' } }
    if ($Mode -eq 'owner') { return @{ http = '8190'; compat = '8191'; base = 'http://127.0.0.1:8190' } }
    Stop-PrivateFull 'endpoint_mode_invalid'
}

function Get-EnvPaths([string]$Mode) {
    if ($Mode -eq 'staging') { return @{ piwigo = $PiwigoStagingEnvPath; immich = $ImmichStagingEnvPath } }
    if ($Mode -eq 'owner') { return @{ piwigo = $PiwigoOwnerEnvPath; immich = $ImmichOwnerEnvPath } }
    Stop-PrivateFull 'endpoint_mode_invalid'
}

function Get-PiwigoComposePrefix([string]$EnvRelative) {
    return @('docker', 'compose', '--env-file', $EnvRelative, '-f', $piwigoCompose, '-f', $piwigoOverride, '-p', $piwigoProject, '--profile', 'ops')
}

function Get-ImmichComposePrefix([string]$EnvRelative, [string]$EnvWslPath) {
    return @('env', ('IMMICH_SPIKE_ENV_FILE=' + $EnvWslPath), 'docker', 'compose', '--env-file', $EnvRelative,
        '-f', $immichCompose, '-f', $immichOverride, '-p', $immichProject,
        '--profile', 'immich-spike', '--profile', 'immich-ml', '--profile', 'immich-web-compat', '--profile', 'immich-gateway-integration')
}

function Assert-PortBindings([object]$Config, [hashtable]$Spec) {
    $services = Get-PropertyValue $Config 'services'
    $piwigo = Get-PropertyValue $services 'piwigo'
    $ports = @((Get-PropertyValue $piwigo 'ports'))
    if ($ports.Count -ne 2) { Stop-PrivateFull 'private_full_port_count_invalid' }
    $actual = @{}
    foreach ($port in $ports) {
        $hostIp = [string](Get-PropertyValue $port 'host_ip')
        $published = [string](Get-PropertyValue $port 'published')
        $target = [string](Get-PropertyValue $port 'target')
        if ($hostIp -ne '127.0.0.1' -or $published -notin @($Spec.http, $Spec.compat)) { Stop-PrivateFull 'private_full_port_binding_invalid' }
        $actual[$published] = $target
    }
    if ($actual[$Spec.http] -ne '80' -or $actual[$Spec.compat] -ne '8081') { Stop-PrivateFull 'private_full_port_binding_invalid' }
    foreach ($service in $services.PSObject.Properties) {
        if ($service.Name -eq 'piwigo') { continue }
        $servicePorts = Get-PropertyValue $service.Value 'ports'
        if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) { Stop-PrivateFull ('unexpected_host_port_' + $service.Name) }
    }
}

function Assert-DockerManagedVolume([object]$Volumes, [string]$Logical, [string]$Name) {
    $record = Get-PropertyValue $Volumes $Logical
    if ($null -eq $record -or [string](Get-PropertyValue $record 'name') -ne $Name -or [string](Get-PropertyValue $record 'driver') -ne 'local') {
        Stop-PrivateFull ('resolved_volume_identity_invalid_' + $Logical)
    }
    $options = Get-PropertyValue $record 'driver_opts'
    if ($null -ne $options -and -not [string]::IsNullOrWhiteSpace([string](Get-PropertyValue $options 'device'))) {
        Stop-PrivateFull ('resolved_volume_storage_invalid_' + $Logical)
    }
}

function Assert-ControlVolume([object]$Volumes, [string]$Logical, [string]$Name) {
    $record = Get-PropertyValue $Volumes $Logical
    if ($null -eq $record -or [string](Get-PropertyValue $record 'name') -ne $Name -or [string](Get-PropertyValue $record 'driver') -ne 'local') {
        Stop-PrivateFull ('resolved_control_volume_identity_invalid_' + $Logical)
    }
    $options = Get-PropertyValue $record 'driver_opts'
    if ($null -ne $options -and -not [string]::IsNullOrWhiteSpace([string](Get-PropertyValue $options 'device'))) {
        Stop-PrivateFull ('resolved_control_volume_must_not_bind_m_' + $Logical)
    }
}

function Assert-NetworkConfig([object]$Config, [string]$GatewayLogical) {
    $networks = Get-PropertyValue $Config 'networks'
    foreach ($property in $networks.PSObject.Properties) {
        $name = [string](Get-PropertyValue $property.Value 'name')
        if ($name -notmatch '^class_archive_private_full_') { Stop-PrivateFull 'resolved_network_identity_invalid' }
    }
    if ([string](Get-PropertyValue (Get-PropertyValue $networks $GatewayLogical) 'name') -ne $gatewayNetwork) { Stop-PrivateFull 'resolved_gateway_network_invalid' }
}

function Assert-PiwigoConfig([object]$Config, [hashtable]$Spec, [string]$StagingWsl, [string]$ManifestWsl, [string]$ExtensionCacheWsl) {
    if ([string](Get-PropertyValue $Config 'name') -ne $piwigoProject) { Stop-PrivateFull 'piwigo_project_invalid' }
    Assert-PortBindings $Config $Spec
    Assert-NetworkConfig $Config 'immich_gateway'
    $networks = Get-PropertyValue $Config 'networks'
    $gateway = Get-PropertyValue $networks 'immich_gateway'
    $gatewayIpam = Get-PropertyValue $gateway 'ipam'
    $gatewayRanges = @((Get-PropertyValue $gatewayIpam 'config'))
    if ($gatewayRanges.Count -ne 1 -or [string](Get-PropertyValue $gatewayRanges[0] 'subnet') -ne $gatewaySubnet) {
        Stop-PrivateFull 'gateway_subnet_invalid'
    }
    if ((Get-PropertyValue (Get-PropertyValue $networks 'app') 'internal') -eq $true -or
        (Get-PropertyValue (Get-PropertyValue $networks 'immich_gateway') 'internal') -ne $true) { Stop-PrivateFull 'piwigo_network_boundary_invalid' }
    $volumes = Get-PropertyValue $Config 'volumes'
    Assert-ControlVolume $volumes 'piwigo_data' 'class_archive_private_full_v3_control_piwigo_data'
    Assert-DockerManagedVolume $volumes 'piwigo_uploads' 'class_archive_private_full_v3_piwigo_uploads'
    Assert-DockerManagedVolume $volumes 'piwigo_galleries' 'class_archive_private_full_v3_piwigo_galleries'
    Assert-DockerManagedVolume $volumes 'piwigo_derivatives' 'class_archive_private_full_v3_piwigo_derivatives'
    Assert-ControlVolume $volumes 'piwigo_db' 'class_archive_private_full_v3_control_piwigo_db'
    Assert-ControlVolume $volumes 'piwigo_scripts' 'class_archive_private_full_v3_control_piwigo_scripts'
    Assert-DockerManagedVolume $volumes 'backups' 'class_archive_private_full_v3_piwigo_backups'
    $piwigo = Get-PropertyValue (Get-PropertyValue $Config 'services') 'piwigo'
    $environment = Get-PropertyValue $piwigo 'environment'
    if ([string](Get-PropertyValue $environment 'CLASS_ARCHIVE_PRIVATE_REAL_FULL') -ne '1') { Stop-PrivateFull 'private_real_full_gate_missing' }
    $staging = @((Get-PropertyValue $piwigo 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/private-real-full/staging' })
    if ($staging.Count -ne 1 -or [string](Get-PropertyValue $staging[0] 'type') -ne 'bind' -or [string](Get-PropertyValue $staging[0] 'source') -ne $StagingWsl -or
        (Get-PropertyValue $staging[0] 'read_only') -ne $true -or (Get-PropertyValue (Get-PropertyValue $staging[0] 'bind') 'create_host_path') -ne $false) {
        Stop-PrivateFull 'staging_mount_not_read_only'
    }
    $manifest = @((Get-PropertyValue $piwigo 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/private-real-full/manifests/full-real-import-manifest.json' })
    if ($manifest.Count -ne 1 -or [string](Get-PropertyValue $manifest[0] 'type') -ne 'bind' -or [string](Get-PropertyValue $manifest[0] 'source') -ne $ManifestWsl -or
        (Get-PropertyValue $manifest[0] 'read_only') -ne $true -or (Get-PropertyValue (Get-PropertyValue $manifest[0] 'bind') 'create_host_path') -ne $false) {
        Stop-PrivateFull 'import_manifest_mount_not_read_only'
    }
    $cache = @((Get-PropertyValue $piwigo 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/class-archive-extension-cache' })
    if ($cache.Count -ne 1 -or [string](Get-PropertyValue $cache[0] 'type') -ne 'bind' -or [string](Get-PropertyValue $cache[0] 'source') -ne $ExtensionCacheWsl -or
        (Get-PropertyValue $cache[0] 'read_only') -ne $true -or (Get-PropertyValue (Get-PropertyValue $cache[0] 'bind') 'create_host_path') -ne $false) {
        Stop-PrivateFull 'extension_cache_mount_not_read_only'
    }
}

function Assert-ImmichConfig([object]$Config, [hashtable]$Spec, [string]$StagingWsl, [string]$ManifestWsl, [string]$ExtensionCacheWsl, [string]$PhotoUiWsl) {
    if ([string](Get-PropertyValue $Config 'name') -ne $immichProject) { Stop-PrivateFull 'immich_project_invalid' }
    Assert-NetworkConfig $Config 'class_archive_gateway'
    $networks = Get-PropertyValue $Config 'networks'
    foreach ($logical in @('immich_internal', 'immich_ml_internal', 'immich_bridge_internal')) {
        if ((Get-PropertyValue (Get-PropertyValue $networks $logical) 'internal') -ne $true) { Stop-PrivateFull ('immich_network_not_internal_' + $logical) }
    }
    if ((Get-PropertyValue (Get-PropertyValue $networks 'class_archive_gateway') 'external') -ne $true) { Stop-PrivateFull 'immich_gateway_not_external' }
    $volumes = Get-PropertyValue $Config 'volumes'
    Assert-DockerManagedVolume $volumes 'immich_upload' 'class_archive_private_full_v3_immich_upload'
    Assert-DockerManagedVolume $volumes 'immich_model_cache' 'class_archive_private_full_v3_immich_model_cache'
    Assert-ControlVolume $volumes 'immich_db' 'class_archive_private_full_v3_control_immich_db'
    Assert-ControlVolume $volumes 'immich_gateway_secret' 'class_archive_private_full_v3_control_immich_gateway_secret'
    foreach ($logical in @('piwigo_uploads', 'piwigo_galleries')) {
        $record = Get-PropertyValue $volumes $logical
        if ($null -eq $record -or [string](Get-PropertyValue $record 'name') -notmatch '^class_archive_private_full_v3_piwigo_' -or (Get-PropertyValue $record 'external') -ne $true) {
            Stop-PrivateFull ('immich_piwigo_volume_invalid_' + $logical)
        }
    }
    $services = Get-PropertyValue $Config 'services'
    foreach ($service in $services.PSObject.Properties) {
        $ports = Get-PropertyValue $service.Value 'ports'
        if ($null -ne $ports -and @($ports).Count -gt 0) { Stop-PrivateFull ('immich_host_port_forbidden_' + $service.Name) }
        foreach ($mount in @((Get-PropertyValue $service.Value 'volumes'))) {
            if ([string](Get-PropertyValue $mount 'source') -in @($StagingWsl, $ManifestWsl, $ExtensionCacheWsl) -or [string](Get-PropertyValue $mount 'target') -like '/private-real-full*' -or [string](Get-PropertyValue $mount 'target') -eq '/class-archive-extension-cache') {
                Stop-PrivateFull 'staging_mounted_into_immich'
            }
        }
    }
    $web = Get-PropertyValue $services 'immich-web-compat'
    $environment = Get-PropertyValue $web 'environment'
    if ([string](Get-PropertyValue $environment 'CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT') -ne $Spec.compat -or
        [string](Get-PropertyValue $environment 'CLASS_ARCHIVE_CORE_PUBLIC_PORT') -ne $Spec.http -or
        [string](Get-PropertyValue $environment 'CLASS_ARCHIVE_PHOTO_UI_ROOT') -ne '/photo-ui') { Stop-PrivateFull 'immich_compat_endpoint_invalid' }
    $photoUi = @((Get-PropertyValue $web 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/photo-ui' })
    if ($photoUi.Count -ne 1 -or [string](Get-PropertyValue $photoUi[0] 'type') -ne 'bind' -or [string](Get-PropertyValue $photoUi[0] 'source') -ne $PhotoUiWsl -or (Get-PropertyValue $photoUi[0] 'read_only') -ne $true) {
        Stop-PrivateFull 'immich_photo_ui_mount_invalid'
    }
    $webNetworks = Get-PropertyValue $web 'networks'
    if ([string](Get-PropertyValue (Get-PropertyValue $webNetworks 'class_archive_gateway') 'ipv4_address') -ne $gatewayCompatAddress) {
        Stop-PrivateFull 'immich_gateway_address_invalid'
    }
    $server = Get-PropertyValue $services 'immich-server'
    foreach ($target in @('/external/piwigo-upload', '/external/piwigo-galleries')) {
        $mount = @((Get-PropertyValue $server 'volumes') | Where-Object { [string](Get-PropertyValue $_ 'target') -eq $target })
        if ($mount.Count -ne 1 -or (Get-PropertyValue $mount[0] 'read_only') -ne $true) { Stop-PrivateFull 'immich_original_mount_not_read_only' }
    }
}

function Get-ValidatedEndpoint([string]$Mode) {
    $spec = Get-EndpointSpec $Mode
    $paths = Get-EnvPaths $Mode
    $piwigoFull = Assert-IgnoredUntrackedFile $paths.piwigo ('piwigo_' + $Mode + '_env')
    $immichFull = Assert-IgnoredUntrackedFile $paths.immich ('immich_' + $Mode + '_env')
    $piwigoAllowed = @('COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_HTTP_PORT','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_GATEWAY_NETWORK','CLASS_ARCHIVE_BASE_URL','CLASS_ARCHIVE_TIMEZONE','PRIVATE_FULL_MANAGED_STORAGE_MODE','FULL_REAL_STAGING_PATH','FULL_REAL_IMPORT_MANIFEST_PATH','PRIVATE_FULL_EXTENSION_CACHE_PATH','PIWIGO_UID','PIWIGO_GID','PIWIGO_DATA_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME','PIWIGO_DERIVATIVES_VOLUME','PIWIGO_DB_VOLUME','PIWIGO_SCRIPTS_VOLUME','PIWIGO_BACKUPS_VOLUME','PIWIGO_IMAGE','MARIADB_IMAGE','DB_NAME','DB_USER','DB_PASSWORD','DB_ROOT_PASSWORD','PIWIGO_ADMIN_USERNAME','PIWIGO_ADMIN_EMAIL','CLASS_ARCHIVE_CLAIM_CODE_PEPPER','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET','SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_ENCRYPTION')
    $immichAllowed = @('IMMICH_COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_CORE_PUBLIC_PORT','CLASS_ARCHIVE_GATEWAY_NETWORK','PRIVATE_FULL_MANAGED_STORAGE_MODE','IMMICH_UPLOAD_VOLUME','IMMICH_MODEL_CACHE_VOLUME','IMMICH_DB_VOLUME','IMMICH_GATEWAY_SECRET_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME','DB_PASSWORD','DB_USERNAME','DB_DATABASE_NAME','TZ')
    $piwigo = Read-StrictEnvironment $piwigoFull $piwigoAllowed ('piwigo_' + $Mode + '_env')
    $immich = Read-StrictEnvironment $immichFull $immichAllowed ('immich_' + $Mode + '_env')

    Assert-ExactValue $piwigo 'COMPOSE_PROJECT_NAME' $piwigoProject 'piwigo_project_invalid'
    Assert-ExactValue $piwigo 'CLASS_ARCHIVE_HTTP_PORT' $spec.http 'private_http_port_invalid'
    Assert-ExactValue $piwigo 'CLASS_ARCHIVE_COMPAT_HTTP_PORT' $spec.compat 'private_compat_port_invalid'
    Assert-ExactValue $piwigo 'CLASS_ARCHIVE_GATEWAY_NETWORK' $gatewayNetwork 'gateway_network_invalid'
    Assert-ExactValue $piwigo 'CLASS_ARCHIVE_BASE_URL' $spec.base 'private_base_url_invalid'
    Assert-ExactValue $piwigo 'CLASS_ARCHIVE_TIMEZONE' 'Asia/Shanghai' 'private_timezone_invalid'
    Assert-ExactValue $piwigo 'PIWIGO_IMAGE' 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84' 'piwigo_image_invalid'
    Assert-ExactValue $piwigo 'MARIADB_IMAGE' 'mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf' 'mariadb_image_invalid'
    foreach ($smtp in @('SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_ENCRYPTION')) { if ($piwigo.ContainsKey($smtp) -and [string]$piwigo[$smtp] -ne '') { Stop-PrivateFull 'smtp_must_remain_disabled' } }
    foreach ($secret in @('DB_PASSWORD','DB_ROOT_PASSWORD','CLASS_ARCHIVE_CLAIM_CODE_PEPPER','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET')) { Assert-Secret $piwigo $secret ('piwigo_' + $secret.ToLowerInvariant() + '_invalid') }
    Assert-Volume $piwigo 'PIWIGO_DATA_VOLUME' 'class_archive_private_full_v3_control_piwigo_data'
    Assert-Volume $piwigo 'PIWIGO_UPLOADS_VOLUME' 'class_archive_private_full_v3_piwigo_uploads'
    Assert-Volume $piwigo 'PIWIGO_GALLERIES_VOLUME' 'class_archive_private_full_v3_piwigo_galleries'
    Assert-Volume $piwigo 'PIWIGO_DERIVATIVES_VOLUME' 'class_archive_private_full_v3_piwigo_derivatives'
    Assert-Volume $piwigo 'PIWIGO_DB_VOLUME' 'class_archive_private_full_v3_control_piwigo_db'
    Assert-Volume $piwigo 'PIWIGO_SCRIPTS_VOLUME' 'class_archive_private_full_v3_control_piwigo_scripts'
    Assert-Volume $piwigo 'PIWIGO_BACKUPS_VOLUME' 'class_archive_private_full_v3_piwigo_backups'
    Assert-DockerManagedStorage $piwigo
    $manifestWsl = Assert-PrivateManifest (Require-Value $piwigo 'FULL_REAL_IMPORT_MANIFEST_PATH' 'import_manifest_path')
    $manifestDigest = Get-PrivateManifestDigest $manifestWsl
    $stagingWsl = Assert-OpaqueStagingDirectory (Require-Value $piwigo 'FULL_REAL_STAGING_PATH' 'staging_path') $manifestDigest
    $extensionCacheWsl = Assert-PrivateExtensionCache (Require-Value $piwigo 'PRIVATE_FULL_EXTENSION_CACHE_PATH' 'extension_cache_path')

    Assert-ExactValue $immich 'IMMICH_COMPOSE_PROJECT_NAME' $immichProject 'immich_project_invalid'
    Assert-ExactValue $immich 'CLASS_ARCHIVE_COMPAT_HTTP_PORT' $spec.compat 'immich_compat_port_invalid'
    Assert-ExactValue $immich 'CLASS_ARCHIVE_CORE_PUBLIC_PORT' $spec.http 'immich_core_public_port_invalid'
    Assert-ExactValue $immich 'CLASS_ARCHIVE_GATEWAY_NETWORK' $gatewayNetwork 'gateway_network_invalid'
    Assert-ExactValue $immich 'PRIVATE_FULL_MANAGED_STORAGE_MODE' $script:managedStorageMode 'managed_storage_mode_invalid'
    Assert-Secret $immich 'DB_PASSWORD' 'immich_db_secret_invalid'
    Assert-Volume $immich 'IMMICH_UPLOAD_VOLUME' 'class_archive_private_full_v3_immich_upload'
    Assert-Volume $immich 'IMMICH_MODEL_CACHE_VOLUME' 'class_archive_private_full_v3_immich_model_cache'
    Assert-Volume $immich 'IMMICH_DB_VOLUME' 'class_archive_private_full_v3_control_immich_db'
    Assert-Volume $immich 'IMMICH_GATEWAY_SECRET_VOLUME' 'class_archive_private_full_v3_control_immich_gateway_secret'
    Assert-Volume $immich 'PIWIGO_UPLOADS_VOLUME' 'class_archive_private_full_v3_piwigo_uploads'
    Assert-Volume $immich 'PIWIGO_GALLERIES_VOLUME' 'class_archive_private_full_v3_piwigo_galleries'

    $piwigoRelative = Get-ProjectRelativePath $piwigoFull
    $immichRelative = Get-ProjectRelativePath $immichFull
    $immichWsl = Get-WslPath $immichFull
    $photoUiWsl = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\photo-ui')
    $piwigoPrefix = Get-PiwigoComposePrefix $piwigoRelative
    $immichPrefix = Get-ImmichComposePrefix $immichRelative $immichWsl
    $piwigoConfig = Invoke-ComposeJson @($piwigoPrefix + @('config', '--format', 'json'))
    $immichConfig = Invoke-ComposeJson @($immichPrefix + @('config', '--format', 'json'))
    Assert-PiwigoConfig $piwigoConfig $spec $stagingWsl $manifestWsl $extensionCacheWsl
    Assert-ImmichConfig $immichConfig $spec $stagingWsl $manifestWsl $extensionCacheWsl $photoUiWsl
    return @{ mode = $Mode; spec = $spec; piwigo = $piwigo; immich = $immich; piwigoPrefix = $piwigoPrefix; immichPrefix = $immichPrefix }
}

function Assert-EndpointSecretParity([hashtable]$Staging, [hashtable]$Owner) {
    foreach ($name in @('DB_NAME','DB_USER','DB_PASSWORD','DB_ROOT_PASSWORD','CLASS_ARCHIVE_CLAIM_CODE_PEPPER','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET','PRIVATE_FULL_MANAGED_STORAGE_MODE','FULL_REAL_STAGING_PATH','FULL_REAL_IMPORT_MANIFEST_PATH','PRIVATE_FULL_EXTENSION_CACHE_PATH','PIWIGO_DATA_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME','PIWIGO_DERIVATIVES_VOLUME','PIWIGO_DB_VOLUME','PIWIGO_SCRIPTS_VOLUME','PIWIGO_BACKUPS_VOLUME')) {
        if (-not [string]::Equals([string]$Staging.piwigo[$name], [string]$Owner.piwigo[$name], [StringComparison]::Ordinal)) { Stop-PrivateFull 'cutover_piwigo_state_parity_invalid' }
    }
    foreach ($name in @('DB_PASSWORD','DB_USERNAME','DB_DATABASE_NAME','PRIVATE_FULL_MANAGED_STORAGE_MODE','IMMICH_UPLOAD_VOLUME','IMMICH_MODEL_CACHE_VOLUME','IMMICH_DB_VOLUME','IMMICH_GATEWAY_SECRET_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME')) {
        if (-not [string]::Equals([string]$Staging.immich[$name], [string]$Owner.immich[$name], [StringComparison]::Ordinal)) { Stop-PrivateFull 'cutover_immich_state_parity_invalid' }
    }
}

function Assert-ManagedPayloadRuntimeSupported {
    # Re-run after a cold staging/cutover start rather than trusting a prior
    # probe. Piwigo calls setfacl and MediaGuard needs real 0660 media modes.
    if ($script:managedStorageMode -ne 'DOCKER_MANAGED_POSIX_VOLUMES') {
        Stop-PrivateFull 'payload_storage_mode_unsupported'
    }
    $lines = Invoke-PrivateFullStorage 'probe'
    if (@($lines | Where-Object { $_ -match '^PRIVATE_FULL_STORAGE=PASS action=probe mode=DOCKER_MANAGED_POSIX_VOLUMES payload=DOCKER_VOLUME_POSIX_PROBED at_rest_owner_acl=DOCKER_DESKTOP_LOCAL_ONLY ' }).Count -ne 1) {
        Stop-PrivateFull 'payload_posix_probe_invalid'
    }
}

function Convert-CidrToRange([string]$Cidr) {
    if ($Cidr -notmatch '^([0-9]{1,3}(?:\.[0-9]{1,3}){3})/([0-9]|[12][0-9]|3[0-2])$') { Stop-PrivateFull 'docker_network_cidr_invalid' }
    try { $bytes = [Net.IPAddress]::Parse([string]$Matches[1]).GetAddressBytes() } catch { Stop-PrivateFull 'docker_network_cidr_invalid' }
    if ($bytes.Length -ne 4) { Stop-PrivateFull 'docker_network_cidr_invalid' }
    $prefix = [int]$Matches[2]
    $address = ([UInt64]$bytes[0] -shl 24) -bor ([UInt64]$bytes[1] -shl 16) -bor ([UInt64]$bytes[2] -shl 8) -bor [UInt64]$bytes[3]
    $size = [UInt64][Math]::Pow(2, (32 - $prefix))
    $mask = ([UInt64][Math]::Pow(2, 32) - $size)
    $start = $address -band $mask
    return @{ start = $start; end = ($start + $size - 1) }
}

function Test-CidrOverlap([string]$Left, [string]$Right) {
    $leftRange = Convert-CidrToRange $Left
    $rightRange = Convert-CidrToRange $Right
    return -not ($leftRange.end -lt $rightRange.start -or $rightRange.end -lt $leftRange.start)
}

function Assert-GatewaySubnetAvailable {
    $names = @(Invoke-PrivateFullDocker @('network','ls','--format','{{.Name}}') 'docker_network_list_failed' 20 -Capture)
    foreach ($name in $names) {
        $networkName = [string]$name
        if ([string]::IsNullOrWhiteSpace($networkName) -or $networkName -eq $gatewayNetwork) { continue }
        $recordText = @(Invoke-PrivateFullDocker @('network','inspect',$networkName) 'docker_network_inspect_failed' 20 -Capture)
        try { $records = ([string]::Join("`n", $recordText) | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-PrivateFull 'docker_network_inspect_invalid' }
        foreach ($record in @($records)) {
            foreach ($config in @((Get-PropertyValue (Get-PropertyValue $record 'IPAM') 'Config'))) {
                $subnet = [string](Get-PropertyValue $config 'Subnet')
                if ([string]::IsNullOrWhiteSpace($subnet) -or $subnet.Contains(':')) { continue }
                if (Test-CidrOverlap $gatewaySubnet $subnet) { Stop-PrivateFull 'gateway_subnet_already_allocated' }
            }
        }
    }
}

function Assert-FullOwnerNotBound {
    $lines = @(Invoke-PrivateFullDocker @('ps','--filter','label=com.classarchive.scope=private-real-full','--format','{{.Ports}}') 'docker_ps_failed' 20 -Capture)
    if (([string]::Join("`n", $lines)) -match '127\.0\.0\.1:8190->') { Stop-PrivateFull 'owner_runtime_currently_bound' }
}

function Assert-LegacyVolumesPresent {
    $names = @('class_archive_private_qa_piwigo_data','class_archive_private_qa_piwigo_uploads','class_archive_private_qa_piwigo_galleries','class_archive_private_qa_piwigo_derivatives','class_archive_private_qa_piwigo_db','class_archive_private_qa_piwigo_scripts','class_archive_private_qa_piwigo_backups','class_archive_private_qa_immich_upload','class_archive_private_qa_immich_model_cache','class_archive_private_qa_immich_db','class_archive_private_qa_immich_gateway_secret')
    [void](Invoke-PrivateFullDocker (@('volume','inspect') + $names) 'legacy_private_qa_volumes_missing' 30)
}

function Invoke-PrivateQa([string]$LegacyAction) {
    [void](Invoke-PrivateFullChildPowerShell $legacyRunner @($LegacyAction) ('legacy_private_qa_' + $LegacyAction + '_failed') 180)
}

function Assert-CleanCheckout {
    $dirty = @(& git -C $projectRoot status --porcelain)
    if ($LASTEXITCODE -ne 0 -or $dirty.Count -gt 0) { Stop-PrivateFull 'refusing_dirty_cutover' }
}

function Assert-CutoverApproval {
    $full = Assert-IgnoredUntrackedFile $CutoverApprovalPath 'cutover_approval'
    try { $approval = Get-Content -LiteralPath $full -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-PrivateFull 'cutover_approval_invalid' }
    if ([string]$approval.version -ne '1' -or [string]$approval.full_real_import -ne 'PASS' -or [string]$approval.full_real_browser_e2e -ne 'PASS' -or [string]$approval.source_full_integrity -ne 'PASS' -or [string]$approval.full_real_owner_ready -ne 'YES') {
        Stop-PrivateFull 'cutover_approval_gates_missing'
    }
    if ([string]$approval.file_mode_policy -ne 'PASS') {
        Stop-PrivateFull 'cutover_file_mode_policy_missing'
    }
    if ([string]$approval.backing_exfat_local_only -ne 'ACKNOWLEDGED_PRIVATE_QA_LIMITATION') {
        Stop-PrivateFull 'cutover_backing_storage_limitation_unacknowledged'
    }
}

function Wait-ForHttp([string]$Uri, [int]$ExpectedPort, [string]$Code) {
    $uriObject = [Uri]$Uri
    if ($uriObject.Host -ne '127.0.0.1' -or $uriObject.Port -ne $ExpectedPort -or $uriObject.Scheme -ne 'http') { Stop-PrivateFull 'probe_target_invalid' }
    foreach ($attempt in 1..30) {
        try {
            $response = Invoke-WebRequest -Uri $Uri -UseBasicParsing -TimeoutSec 5 -MaximumRedirection 0 -ErrorAction Stop
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) { return }
        }
        catch { }
        Start-Sleep -Seconds 2
    }
    Stop-PrivateFull $Code
}

function Get-CoreRuntimeHttpState([hashtable]$Endpoint) {
    $uri = $Endpoint.spec.base + '/identification.php'
    $uriObject = [Uri]$uri
    if ($uriObject.Host -ne '127.0.0.1' -or $uriObject.Port -ne [int]$Endpoint.spec.http -or $uriObject.Scheme -ne 'http') { Stop-PrivateFull 'probe_target_invalid' }
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) { Stop-PrivateFull 'runtime_curl_missing' }
    foreach ($attempt in 1..30) {
        # Invoke-WebRequest consumes a Windows PowerShell 5.1 WebException
        # body before callers can inspect its HttpWebResponse stream. Use the
        # built-in curl.exe instead, with an unambiguous local status marker,
        # so the exact bounded maintenance body has identical semantics in
        # Windows PowerShell and PowerShell 7.
        $lines = @(& $curl --silent --show-error --max-time 5 --output - --write-out "`nCLASS_ARCHIVE_STATUS:%{http_code}" $uri 2>&1)
        $exitCode = $LASTEXITCODE
        if ($exitCode -eq 0 -and $lines.Count -ge 1) {
            # A normal Piwigo HTML response may have no terminal newline, so
            # the curl status marker can be appended to its final HTML line.
            # Reassemble the exact response stream and parse only a marker at
            # the end; never infer success from a partial/ambiguous body.
            $combined = [string]::Join("`n", @($lines | ForEach-Object { [string]$_ }))
            $match = [regex]::Match($combined, '(?s)\A(?<body>.*)\r?\nCLASS_ARCHIVE_STATUS:(?<status>\d{3})\z')
            if ($match.Success) {
                $status = [int]$match.Groups['status'].Value
                if ($status -ge 200 -and $status -lt 400) { return 'READY' }
                if ($status -eq 503) {
                    $body = $match.Groups['body'].Value.TrimEnd("`r", "`n")
                    if ($body -eq 'Class Archive maintenance mode.') { return 'MAINTENANCE_FAIL_CLOSED' }
                }
            }
        }
        Start-Sleep -Seconds 2
    }
    Stop-PrivateFull 'runtime_core_http_failed'
}

function Wait-ForContainerRunning([string]$Name, [string]$Code) {
    foreach ($attempt in 1..30) {
        $record = Get-RuntimeContainer $Name
        $state = Get-PropertyValue $record 'State'
        if ((Get-PropertyValue $state 'Running') -eq $true) { return $record }
        Start-Sleep -Seconds 2
    }
    Stop-PrivateFull $Code
}

function Get-RuntimeContainer([string]$Name) {
    $output = @(Invoke-PrivateFullDocker @('inspect',$Name) 'runtime_container_missing' 20 -Capture)
    try { $records = ([string]::Join("`n", $output) | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-PrivateFull 'runtime_container_inspect_invalid' }
    if (@($records).Count -ne 1) { Stop-PrivateFull 'runtime_container_inspect_invalid' }
    return @($records)[0]
}

function Wait-ForContainerHealthy([string]$Name, [string]$Code) {
    foreach ($attempt in 1..30) {
        $record = Get-RuntimeContainer $Name
        $state = Get-PropertyValue $record 'State'
        if ((Get-PropertyValue $state 'Running') -eq $true) {
            $health = Get-PropertyValue (Get-PropertyValue $state 'Health') 'Status'
            if ($health -eq 'healthy' -or [string]::IsNullOrWhiteSpace([string]$health)) { return $record }
        }
        Start-Sleep -Seconds 2
    }
    Stop-PrivateFull $Code
}

function Assert-RuntimeDockerPorts([string]$Container, [string[]]$Expected) {
    $ports = @(Invoke-PrivateFullDocker @('port',$Container) 'runtime_port_inspect_failed' 20 -Capture)
    $actual = @($ports | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) } | ForEach-Object { ([string]$_).Trim() } | Sort-Object)
    $wanted = @($Expected | Sort-Object)
    if ($actual.Count -ne $wanted.Count) { Stop-PrivateFull 'runtime_port_binding_invalid' }
    for ($index = 0; $index -lt $wanted.Count; $index++) {
        if (-not [string]::Equals($actual[$index], $wanted[$index], [StringComparison]::Ordinal)) { Stop-PrivateFull 'runtime_port_binding_invalid' }
    }
}

function Assert-RuntimeDockerVolume([string]$Name, [switch]$Control) {
    $output = @(Invoke-PrivateFullDocker @('volume','inspect',$Name) 'runtime_volume_missing' 20 -Capture)
    try { $records = ([string]::Join("`n", $output) | ConvertFrom-Json -ErrorAction Stop) } catch { Stop-PrivateFull 'runtime_volume_inspect_invalid' }
    if (@($records).Count -ne 1) { Stop-PrivateFull 'runtime_volume_inspect_invalid' }
    $record = @($records)[0]
    if ([string](Get-PropertyValue $record 'Driver') -ne 'local') { Stop-PrivateFull 'runtime_volume_driver_invalid' }
    $options = Get-PropertyValue $record 'Options'
    $device = [string](Get-PropertyValue $options 'device')
    if ($Control) {
        if (-not [string]::IsNullOrWhiteSpace($device)) { Stop-PrivateFull 'runtime_control_volume_must_not_bind_payload' }
    }
    elseif (-not [string]::IsNullOrWhiteSpace($device)) { Stop-PrivateFull 'runtime_payload_volume_must_be_docker_managed' }
}

function Assert-RuntimeReadonlyInputs([object]$Container, [string]$StagingWsl, [string]$ManifestWsl, [string]$ExtensionCacheWsl) {
    $mounts = @((Get-PropertyValue $Container 'Mounts'))
    foreach ($expected in @(
        @{ target = '/private-real-full/staging'; source = $StagingWsl },
        @{ target = '/private-real-full/manifests/full-real-import-manifest.json'; source = $ManifestWsl },
        @{ target = '/class-archive-extension-cache'; source = $ExtensionCacheWsl }
    )) {
        $matches = @($mounts | Where-Object { [string](Get-PropertyValue $_ 'Destination') -eq $expected.target })
        if ($matches.Count -ne 1 -or [string](Get-PropertyValue $matches[0] 'Type') -ne 'bind' -or [string](Get-PropertyValue $matches[0] 'Source') -ne $expected.source -or (Get-PropertyValue $matches[0] 'RW') -ne $false) {
            Stop-PrivateFull 'runtime_import_input_mount_invalid'
        }
    }
}

function Assert-EndpointRuntime([hashtable]$Endpoint) {
    Assert-ManagedPayloadRuntimeSupported
    $piwigoContainerName = $piwigoProject + '-piwigo-1'
    $piwigoDbContainerName = $piwigoProject + '-db-1'
    $compatContainerName = $immichProject + '-immich-web-compat-1'
    $script:stage = 'runtime_piwigo'
    # The plugin bootstrapper intentionally makes Piwigo return an exact 503
    # maintenance response. Docker therefore reports this HTTP-healthcheck as
    # unhealthy even though the fail-closed boundary is working. Require a
    # running container here and decide the special state through the exact
    # loopback response below; all other runtime services remain health-gated.
    $piwigo = Wait-ForContainerRunning $piwigoContainerName 'runtime_piwigo_not_running'
    $script:stage = 'runtime_piwigo_db'
    [void](Wait-ForContainerHealthy $piwigoDbContainerName 'runtime_piwigo_db_not_healthy')
    $script:stage = 'runtime_compat'
    [void](Wait-ForContainerHealthy $compatContainerName 'runtime_compat_not_healthy')
    $script:stage = 'runtime_ports'
    $expectedPiwigoPorts = @(
        ('80/tcp -> 127.0.0.1:' + [string]$Endpoint.spec.http)
        ('8081/tcp -> 127.0.0.1:' + [string]$Endpoint.spec.compat)
    )
    Assert-RuntimeDockerPorts -Container $piwigoContainerName -Expected $expectedPiwigoPorts
    foreach ($container in @($piwigoDbContainerName, $compatContainerName)) {
        Assert-RuntimeDockerPorts -Container $container -Expected @()
    }
    $script:stage = 'runtime_volumes'
    Assert-RuntimeDockerVolume 'class_archive_private_full_v3_piwigo_uploads'
    Assert-RuntimeDockerVolume 'class_archive_private_full_v3_piwigo_galleries'
    Assert-RuntimeDockerVolume 'class_archive_private_full_v3_piwigo_derivatives'
    # The full Immich index is deliberately deferred until photo/album
    # cutover has released the old sample-QA capacity. Only Piwigo and the
    # compatibility BFF are required for the safe full-library browse path.
    foreach ($control in @('class_archive_private_full_v3_control_piwigo_data','class_archive_private_full_v3_control_piwigo_db','class_archive_private_full_v3_control_piwigo_scripts')) {
        Assert-RuntimeDockerVolume $control -Control
    }
    $script:stage = 'runtime_import_mounts'
    Assert-RuntimeReadonlyInputs $piwigo $Endpoint.piwigo.FULL_REAL_STAGING_PATH $Endpoint.piwigo.FULL_REAL_IMPORT_MANIFEST_PATH $Endpoint.piwigo.PRIVATE_FULL_EXTENSION_CACHE_PATH
    $script:stage = 'runtime_http'
    $coreHttpState = Get-CoreRuntimeHttpState $Endpoint
    $piwigoHealth = [string](Get-PropertyValue (Get-PropertyValue (Get-PropertyValue $piwigo 'State') 'Health') 'Status')
    if ($coreHttpState -eq 'READY' -and -not [string]::IsNullOrWhiteSpace($piwigoHealth) -and $piwigoHealth -ne 'healthy') {
        Stop-PrivateFull 'runtime_piwigo_not_healthy'
    }
    Wait-ForHttp ('http://127.0.0.1:' + $Endpoint.spec.compat + '/healthz') ([int]$Endpoint.spec.compat) 'runtime_compat_http_failed'
    return @{ core_http = $coreHttpState; piwigo_health = $(if ([string]::IsNullOrWhiteSpace($piwigoHealth)) { 'not_configured' } else { $piwigoHealth }) }
}

function Start-Endpoint([hashtable]$Endpoint) {
    Invoke-Compose $Endpoint.piwigoPrefix @('up', '-d', '--force-recreate', 'db', 'piwigo')
    # The compatibility BFF is a self-contained policy client. Starting it
    # alone keeps browsing available while full face/search indexing remains a
    # separate background phase and avoids allocating ML cache before cutover.
    Invoke-Compose $Endpoint.immichPrefix @('up', '-d', '--force-recreate', 'immich-web-compat')
}

function Stop-Endpoint([hashtable]$Endpoint) {
    Invoke-Compose $Endpoint.immichPrefix @('stop')
    Invoke-Compose $Endpoint.piwigoPrefix @('stop', 'piwigo', 'db')
}

function Assert-OwnerPiwigoStoppedForBackup {
    # `docker compose stop` returning zero only proves that Compose accepted
    # the request. The backup service is allowed to run only after the exact
    # owner writer container has reached the non-running state, while MariaDB
    # and the compatibility BFF remain available.
    $name = $piwigoProject + '-piwigo-1'
    $state = @(Invoke-PrivateFullDocker @('inspect','--format','{{.State.Running}}|{{.State.Status}}',$name) 'owner_backup_piwigo_not_stopped' 20 -Capture)
    if ($state.Count -ne 1 -or ([string]$state[0]).Trim() -ne 'false|exited') {
        Stop-PrivateFull 'owner_backup_piwigo_not_stopped'
    }
}

function Invoke-OwnerBusinessBackup([hashtable]$Endpoint) {
    if ([string]$Endpoint.mode -ne 'owner') { Stop-PrivateFull 'owner_backup_endpoint_invalid' }

    # Never convert a fail-closed maintenance/degraded application into a
    # backup success. The owner runtime must already be serving its exact
    # loopback boundary before we briefly quiesce the Piwigo writer.
    $script:stage = 'owner_backup_preflight'
    $before = Assert-EndpointRuntime $Endpoint
    if ([string]$before.core_http -ne 'READY') { Stop-PrivateFull 'owner_backup_runtime_not_ready' }

    # Once stop is attempted, always start Piwigo again in finally. This also
    # covers a partial Compose failure after the writer actually stopped. No
    # database, media, BFF, Immich, restore, or cleanup operation is invoked.
    $restartRequired = $false
    try {
        $script:stage = 'owner_backup_stop_piwigo'
        $restartRequired = $true
        Invoke-Compose $Endpoint.piwigoPrefix @('stop', 'piwigo')
        Assert-OwnerPiwigoStoppedForBackup

        $script:stage = 'owner_backup_create'
        Invoke-Compose $Endpoint.piwigoPrefix @(
            'run', '--rm', '-e', 'CLASS_ARCHIVE_BACKUP_QUIESCED=true', 'backup'
        )

        $script:stage = 'owner_backup_audit'
        Invoke-Compose $Endpoint.piwigoPrefix @(
            'run', '--rm', '-e', 'CLASS_ARCHIVE_BACKUP_AUDIT_WRITE=true', 'backup-audit'
        )
    }
    finally {
        if ($restartRequired) {
            $script:stage = 'owner_backup_restart_piwigo'
            Invoke-Compose $Endpoint.piwigoPrefix @('start', 'piwigo')
            $script:stage = 'owner_backup_verify_runtime'
            $after = Assert-EndpointRuntime $Endpoint
            if ([string]$after.core_http -ne 'READY') { Stop-PrivateFull 'owner_backup_runtime_not_recovered' }
        }
    }
}

try {
    $script:stage = 'validation'
    if ($Action -eq 'backup-owner') {
        if (-not $ConfirmOwnerPrivateBackup.IsPresent) { Stop-PrivateFull 'owner_backup_confirmation_required' }
        $endpoint = Get-ValidatedEndpoint 'owner'
        Assert-GatewaySubnetAvailable
        Invoke-OwnerBusinessBackup $endpoint
        Write-Output 'PRIVATE_FULL=PASS action=backup-owner endpoint=8190_8191 backup=CREATED_AND_AUDITED restore=NOT_RUN runtime=RECOVERED scope=OWNER_PRIVATE_FULL'
        exit 0
    }

    $singleEndpointActions = @{
        'validate' = 'staging'
        'config' = 'staging'
        'ps' = 'staging'
        'runtime-staging' = 'staging'
        'up-staging' = 'staging'
        'stop-staging' = 'staging'
        'validate-owner' = 'owner'
        'runtime-owner' = 'owner'
    }
    if ($singleEndpointActions.ContainsKey($Action)) {
        $endpointMode = [string]$singleEndpointActions[$Action]
        $endpoint = Get-ValidatedEndpoint $endpointMode
        Assert-GatewaySubnetAvailable
        if ($Action -eq 'up-staging') {
            Assert-FullOwnerNotBound
            Assert-ManagedPayloadRuntimeSupported
            $script:stage = 'start_staging'
            Start-Endpoint $endpoint
        }
        elseif ($Action -eq 'stop-staging') {
            Assert-FullOwnerNotBound
            $script:stage = 'stop_staging'
            Stop-Endpoint $endpoint
        }
        elseif ($Action -eq 'ps') {
            Invoke-Compose $endpoint.piwigoPrefix @('ps')
            Invoke-Compose $endpoint.immichPrefix @('ps')
        }
        elseif ($Action -in @('runtime-staging', 'runtime-owner')) {
            $script:stage = 'runtime_' + $endpointMode
            $runtime = Assert-EndpointRuntime $endpoint
        }
        if ($Action -in @('runtime-staging', 'runtime-owner')) {
            $endpointLabel = [string]$endpoint.spec.http + '_' + [string]$endpoint.spec.compat
            Write-Output ('PRIVATE_FULL=PASS action=' + $Action + ' endpoint=' + $endpointLabel + ' evidence=RUNTIME_BOUNDARY_VALIDATED core_http=' + $runtime.core_http + ' piwigo_health=' + $runtime.piwigo_health + ' ml=DEFERRED_CAPACITY_GUARDED')
        }
        else { Write-Output "PRIVATE_FULL=PASS action=$Action endpoint=$endpointMode evidence=CONFIG_VALIDATED" }
        exit 0
    }

    $staging = Get-ValidatedEndpoint 'staging'
    $owner = Get-ValidatedEndpoint 'owner'
    Assert-EndpointSecretParity $staging $owner
    Assert-ManagedPayloadRuntimeSupported
    Assert-GatewaySubnetAvailable
    if ($Action -eq 'cutover-preflight') {
        Write-Output 'PRIVATE_FULL=PASS action=cutover-preflight endpoints=8290_8291_to_8190_8191 legacy=preserved_no_mutation'
        exit 0
    }

    if ($Action -eq 'rollback') {
        Assert-CleanCheckout
        $script:stage = 'rollback_stop_owner'
        Stop-Endpoint $owner
        $script:stage = 'rollback_start_legacy'
        Invoke-PrivateQa 'up'
        Write-Output 'PRIVATE_FULL=PASS action=rollback legacy=restored volumes=preserved'
        exit 0
    }

    $script:stage = 'cutover_preconditions'
    Assert-CleanCheckout
    Assert-CutoverApproval
    Assert-LegacyVolumesPresent
    Assert-FullOwnerNotBound
    $script:stage = 'cutover_stop_staging'
    Stop-Endpoint $staging
    $script:stage = 'cutover_stop_legacy'
    Invoke-PrivateQa 'stop'
    $script:legacyStopped = $true
    $script:stage = 'cutover_start_owner'
    Start-Endpoint $owner
    $script:ownerStarted = $true
    $script:stage = 'cutover_probe_owner'
    Wait-ForHttp 'http://127.0.0.1:8190/identification.php' 8190 'owner_core_probe_failed'
    Wait-ForHttp 'http://127.0.0.1:8191/healthz' 8191 'owner_compat_probe_failed'
    Write-Output 'PRIVATE_FULL=PASS action=cutover endpoint=8190_8191 legacy=stopped_not_deleted volumes=preserved'
    exit 0
}
catch {
    $failureCode = $null
    if ($_.Exception.Message -match '^PRIVATE_FULL_STOP:([a-z0-9_]{1,128})$') { $failureCode = [string]$Matches[1] }
    $original = $_.Exception.GetType().Name
    if ($script:legacyStopped) {
        try {
            if ($script:ownerStarted) {
                $script:stage = 'automatic_rollback_stop_owner'
                $ownerForRollback = Get-ValidatedEndpoint 'owner'
                Stop-Endpoint $ownerForRollback
            }
            $script:stage = 'automatic_rollback_start_legacy'
            Invoke-PrivateQa 'up'
        }
        catch {
            Stop-PrivateFull 'cutover_failed_and_rollback_failed'
        }
    }
    if ($null -ne $failureCode) {
        Write-Output "PRIVATE_FULL=FAIL stage=$script:stage code=$failureCode"
        exit 2
    }
    if ($original -notmatch '^[A-Za-z0-9]{1,64}$') { $original = 'Exception' }
    $line = [int]$_.InvocationInfo.ScriptLineNumber
    if ($line -lt 1 -or $line -gt 99999) { $line = 0 }
    Write-Output ('PRIVATE_FULL=FAIL stage=' + $script:stage + ' code=unexpected_' + $original + '_line_' + $line)
    exit 2
}
