[CmdletBinding()]
param()

# Runtime isolation gate for the disposable, localhost-only Immich spike.
#
# Evidence level: RUNTIME_TESTED only.  This intentionally does not create an
# Immich account, external library, asset, thumbnail, API route or browser
# session.  It proves that the real pinned server can boot inside an internal
# network while Piwigo originals remain read-only and unchanged.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$lockPath = Join-Path $projectRoot 'infra\immich-spike\immich-upstream.lock.json'
$supplyScript = Join-Path $projectRoot 'infra\immich-spike\verify-supply-chain.ps1'
$serverName = 'class-archive-immich-spike-immich-server-1'
$databaseName = 'class-archive-immich-spike-database-1'
$redisName = 'class-archive-immich-spike-redis-1'
$networkName = 'class-archive-immich-spike_immich_internal'
$piwigoName = 'class_archive_piwigo-piwigo-1'
$assertions = 0

function Fail([string]$reason) {
    [Console]::Error.WriteLine("IMMICH_RUNTIME_ISOLATION=FAIL evidence=RUNTIME_TESTED reason=$reason assertions=$script:assertions")
    exit 1
}

function Assert-Exact([bool]$condition, [string]$reason) {
    $script:assertions++
    if (-not $condition) {
        Fail $reason
    }
}

function Invoke-UbuntuDocker([string[]]$arguments) {
    # Preserve Docker arguments exactly. WSL's shell-forwarding form corrupts
    # Go templates and shell payloads used by this isolation gate.
    $lines = @(& wsl.exe -d Ubuntu --exec docker @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw ('docker_command_failed_' + ($arguments -join '_'))
    }
    return [string]::Join("`n", $lines)
}

function Get-DockerInspect([string]$name) {
    $json = Invoke-UbuntuDocker @('inspect', $name)
    $parsed = $json | ConvertFrom-Json -ErrorAction Stop
    if ($parsed -is [System.Array]) {
        if ($parsed.Count -ne 1) { throw 'docker_inspect_ambiguous' }
        return $parsed[0]
    }
    return $parsed
}

function Get-DockerNetworkInspect([string]$name) {
    $json = Invoke-UbuntuDocker @('network', 'inspect', $name)
    $parsed = $json | ConvertFrom-Json -ErrorAction Stop
    if ($parsed -is [System.Array]) {
        if ($parsed.Count -ne 1) { throw 'docker_network_inspect_ambiguous' }
        return $parsed[0]
    }
    return $parsed
}

function Invoke-ContainerShell([string]$container, [string]$script) {
    $encoded = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($script))
    return Invoke-UbuntuDocker @('exec', $container, 'sh', '-c', ("echo $encoded | base64 -d | sh"))
}

function Get-PiwigoOriginalFingerprints {
    $script = @'
for p in /var/www/html/piwigo/upload /var/www/html/piwigo/galleries; do
  count=$(find "$p" -type f -printf . | wc -c)
  digest=$(find "$p" -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
  printf 'FINGERPRINT|%s|%s|%s\n' "$p" "$count" "$digest"
done
'@
    $lines = @((Invoke-ContainerShell $piwigoName $script) -split "`r?`n" | Where-Object { $_ -like 'FINGERPRINT|*' })
    if ($lines.Count -ne 2) { throw 'piwigo_fingerprint_output_invalid' }
    $result = @{}
    foreach ($line in $lines) {
        $parts = $line -split '\|', 4
        if ($parts.Count -ne 4 -or $parts[2] -notmatch '^\d+$' -or $parts[3] -notmatch '^[0-9a-f]{64}$') {
            throw 'piwigo_fingerprint_line_invalid'
        }
        $result[$parts[1]] = @{ Count = [int]$parts[2]; Digest = $parts[3] }
    }
    if ($result.Count -ne 2) { throw 'piwigo_fingerprint_duplicate_path' }
    return $result
}

try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $supplyScript -RequireLocal
    if ($LASTEXITCODE -ne 0) { Fail 'supply_chain_not_verified' }
    $lock = Get-Content -LiteralPath $lockPath -Raw | ConvertFrom-Json -ErrorAction Stop

    $server = Get-DockerInspect $serverName
    $database = Get-DockerInspect $databaseName
    $redis = Get-DockerInspect $redisName
    Assert-Exact ($server.State.Running -eq $true -and $server.State.Health.Status -eq 'healthy') 'immich_server_not_healthy'
    Assert-Exact ($database.State.Running -eq $true -and $database.State.Health.Status -eq 'healthy') 'immich_database_not_healthy'
    Assert-Exact ($redis.State.Running -eq $true -and $redis.State.Health.Status -eq 'healthy') 'immich_redis_not_healthy'
    Assert-Exact ($server.Config.Image -eq [string]$lock.images.immich_server.pinned_reference -and $server.Image -eq [string]$lock.images.immich_server.digest) 'immich_server_digest_mismatch'

    $portText = (Invoke-UbuntuDocker @('port', $serverName)).Trim()
    Assert-Exact ([string]::IsNullOrEmpty($portText)) 'immich_host_port_exposed'
    $portBindings = $server.HostConfig.PortBindings
    Assert-Exact ($null -eq $portBindings -or @($portBindings.PSObject.Properties).Count -eq 0) 'immich_host_port_bindings_present'

    $network = Get-DockerNetworkInspect $networkName
    Assert-Exact ($network.Internal -eq $true) 'immich_network_not_internal'
    $networkMembers = @($network.Containers.PSObject.Properties | ForEach-Object { [string]$_.Value.Name })
    foreach ($required in @($serverName, $databaseName, $redisName)) {
        Assert-Exact ($networkMembers -contains $required) 'immich_network_member_missing'
    }
    Assert-Exact (@($networkMembers | Where-Object { $_ -eq $piwigoName }).Count -eq 0) 'piwigo_joined_immich_network'

    $externalMounts = @($server.Mounts | Where-Object { $_.Destination -in @('/external/piwigo-upload', '/external/piwigo-galleries') })
    Assert-Exact ($externalMounts.Count -eq 2) 'piwigo_original_mounts_missing'
    foreach ($mount in $externalMounts) {
        Assert-Exact ($mount.Type -eq 'volume' -and $mount.RW -eq $false) 'piwigo_original_mount_not_read_only'
        Assert-Exact ($mount.Name -in @('class_archive_piwigo_uploads', 'class_archive_piwigo_galleries')) 'unexpected_piwigo_original_volume'
    }
    Assert-Exact ((@($server.Mounts | Where-Object { ([string]$_.Destination) -match '^/var/www/html/piwigo(?:/|$)' }).Count) -eq 0) 'piwigo_runtime_mount_present'
    $piwigoStateMounts = @($server.Mounts | Where-Object {
        $nameProperty = $_.PSObject.Properties['Name']
        $mountName = if ($null -eq $nameProperty) { '' } else { [string]$nameProperty.Value }
        $mountName -match 'piwigo.*(?:db|data)'
    })
    Assert-Exact ($piwigoStateMounts.Count -eq 0) 'piwigo_database_or_data_volume_present'

    $before = Get-PiwigoOriginalFingerprints
    $readOnlyProbe = @'
for p in /external/piwigo-upload /external/piwigo-galleries; do
  count=$(find "$p" -type f -printf . | wc -c)
  if test -r "$p" && test ! -w "$p"; then state=READ_ONLY; else state=UNSAFE; fi
  printf 'READ_ONLY|%s|%s|%s\n' "$p" "$count" "$state"
done
'@
    $readOnlyLines = @((Invoke-ContainerShell $serverName $readOnlyProbe) -split "`r?`n" | Where-Object { $_ -like 'READ_ONLY|*' })
    Assert-Exact ($readOnlyLines.Count -eq 2) 'immich_read_only_probe_output_invalid'
    foreach ($line in $readOnlyLines) {
        Assert-Exact ($line -match '^READ_ONLY\|/external/piwigo-(?:upload|galleries)\|\d+\|READ_ONLY$') 'immich_read_only_mount_probe_failed'
    }

    $nodeProbe = @'
node -e "fetch('http://127.0.0.1:2283/api/server/ping').then(async function(response){console.log('PING|'+response.status+'|'+await response.text())}).catch(function(error){console.error('PING_ERROR|'+error.message);process.exit(1)})"
'@
    $ping = Invoke-ContainerShell $serverName $nodeProbe
    Assert-Exact ($ping -eq 'PING|200|{"res":"pong"}') 'immich_internal_ping_failed'

    $after = Get-PiwigoOriginalFingerprints
    foreach ($path in @('/var/www/html/piwigo/upload', '/var/www/html/piwigo/galleries')) {
        Assert-Exact ($before.ContainsKey($path) -and $after.ContainsKey($path)) 'piwigo_fingerprint_path_missing'
        Assert-Exact ($before[$path].Count -eq $after[$path].Count -and $before[$path].Digest -eq $after[$path].Digest) 'piwigo_originals_changed'
    }

    Write-Output "IMMICH_RUNTIME_ISOLATION=PASS evidence=RUNTIME_TESTED assertions=$assertions"
    Write-Output 'IMMICH_SERVER_PING=PASS network=internal host_port=none'
    Write-Output 'IMMICH_EXTERNAL_MEDIA=READ_ONLY originals_unchanged=PASS'
    Write-Output 'IMMICH_MACHINE_LEARNING=NOT_STARTED'
    exit 0
} catch {
    Fail ('unexpected_' + $_.Exception.GetType().Name)
}
