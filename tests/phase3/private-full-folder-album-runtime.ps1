[CmdletBinding()]
param()

# Count-only local acceptance for the 8290/8291 private-full candidate. The
# wrapper never opens the private manifest, staging tree, or source folders.
# Raw subprocess output is captured and suppressed so an unexpected dependency
# error cannot echo a machine-specific path.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot 'infra\private-full\.env.piwigo.staging'
$runtimePath = Join-Path $PSScriptRoot 'private-full-folder-album-runtime.php'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$curl = "$env:SystemRoot\System32\curl.exe"
$assertions = 0
$exitCode = 0

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function Stop-FolderAlbumRuntime([string]$Code) {
    throw [InvalidOperationException]::new('FOLDER_ALBUM_RUNTIME_STOP:' + $Code)
}

function Assert-Runtime([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-FolderAlbumRuntime $Code }
}

function Get-LocalHttpStatus([string]$Uri, [int]$Port) {
    $parsed = [Uri]$Uri
    Assert-Runtime ($parsed.Scheme -eq 'http' -and $parsed.Host -eq '127.0.0.1' -and $parsed.Port -eq $Port) 'endpoint_target_invalid'
    $output = @(& $curl --silent --show-error --max-time 10 --output NUL --write-out '%{http_code}' $Uri 2>&1)
    $processExit = $LASTEXITCODE
    $statusText = [string]::Join('', @($output | ForEach-Object { [string]$_ })).Trim()
    Assert-Runtime ($processExit -eq 0 -and $statusText -match '^\d{3}$') 'endpoint_probe_failed'
    return [int]$statusText
}

try {
    Assert-Runtime (Test-Path -LiteralPath $wsl -PathType Leaf) 'wsl_unavailable'
    Assert-Runtime (Test-Path -LiteralPath $curl -PathType Leaf) 'curl_unavailable'
    $envItem = Get-Item -LiteralPath $envPath -Force -ErrorAction Stop
    Assert-Runtime (-not $envItem.PSIsContainer -and -not ($envItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'candidate_env_untrusted'
    $relativeEnv = $envItem.FullName.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relativeEnv
    Assert-Runtime ($LASTEXITCODE -eq 0) 'candidate_env_not_ignored'
    Assert-Runtime (@(& git -C $projectRoot ls-files -- $relativeEnv 2>$null).Count -eq 0) 'candidate_env_tracked'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envItem.FullName
    $assertions++

    $runtimeSource = [IO.File]::ReadAllText($runtimePath, [Text.UTF8Encoding]::new($false, $true))
    Assert-Runtime ($runtimeSource.Contains('START TRANSACTION READ ONLY')) 'runtime_read_only_transaction_missing'
    Assert-Runtime (-not ($runtimeSource -match '(?im)\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|OUTFILE)\b')) 'runtime_mutation_statement_detected'
    Assert-Runtime (-not ($runtimeSource -match '(?i)display_name|media_reference|folder_segments|staging_name|`path`|`file`')) 'runtime_private_field_read_detected'

    $composePrefix = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose',
        '--env-file', 'infra/private-full/.env.piwigo.staging',
        '-f', 'infra/docker-compose.yml',
        '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo'
    )
    $containerCommand = $composePrefix + @('ps', '-q', 'piwigo')
    $containerRaw = @(& $wsl @containerCommand 2>&1)
    $containerExit = $LASTEXITCODE
    $containerIds = @($containerRaw | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -match '^[a-f0-9]{64}$' })
    Assert-Runtime ($containerExit -eq 0 -and $containerIds.Count -eq 1) 'candidate_container_unavailable'
    $portRaw = @(& $wsl -d Ubuntu --exec docker port $containerIds[0] 2>&1)
    $portExit = $LASTEXITCODE
    $ports = @($portRaw | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' } | Sort-Object)
    $expectedPorts = @('80/tcp -> 127.0.0.1:8290', '8081/tcp -> 127.0.0.1:8291') | Sort-Object
    Assert-Runtime ($portExit -eq 0 -and $ports.Count -eq 2) 'candidate_port_count_invalid'
    Assert-Runtime ([string]$ports[0] -eq [string]$expectedPorts[0] -and [string]$ports[1] -eq [string]$expectedPorts[1]) 'candidate_not_loopback_only'

    $coreStatus = Get-LocalHttpStatus 'http://127.0.0.1:8290/identification.php' 8290
    $photoStatus = Get-LocalHttpStatus 'http://127.0.0.1:8291/photos' 8291
    Assert-Runtime ($coreStatus -ge 200 -and $coreStatus -lt 400) 'candidate_core_not_ready'
    Assert-Runtime ($photoStatus -ge 200 -and $photoStatus -lt 400) 'candidate_photo_not_ready'

    $compose = $composePrefix + @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_FULL_FOLDER_ALBUM_FIXTURE=1',
        'piwigo', 'php', '/workspace/tests/phase3/private-full-folder-album-runtime.php'
    )
    $raw = @(& $wsl @compose 2>&1)
    $processExit = $LASTEXITCODE
    $safe = @($raw | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=(?:PASS|FAIL)\b'
    })
    Assert-Runtime ($processExit -eq 0 -and $safe.Count -eq 1) 'runtime_query_failed'
    $pass = [regex]::Match(
        $safe[0],
        '^PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=PASS assertions=(?<assertions>\d+) folders_sampled=(?<folders>\d+) items_sampled=(?<items>\d+) provenance_checked=(?<provenance>\d+) duplicate_groups_sampled=(?<groups>\d+) duplicate_items_checked=(?<duplicateItems>\d+) duplicate_albums_checked=(?<duplicateAlbums>\d+) source_names_read=0 source_paths_read=0$'
    )
    Assert-Runtime $pass.Success 'runtime_output_invalid'
    Assert-Runtime ([int]$pass.Groups['assertions'].Value -gt 0) 'runtime_assertions_missing'
    Assert-Runtime ([int]$pass.Groups['folders'].Value -ge 20) 'runtime_folder_sample_too_small'
    Assert-Runtime ([int]$pass.Groups['items'].Value -ge 30) 'runtime_item_sample_too_small'
    Assert-Runtime ([int]$pass.Groups['provenance'].Value -ge 30) 'runtime_provenance_sample_too_small'
    Assert-Runtime ([int]$pass.Groups['groups'].Value -ge 1) 'runtime_duplicate_group_missing'
    Assert-Runtime ([int]$pass.Groups['duplicateItems'].Value -ge 2) 'runtime_duplicate_items_missing'
    Assert-Runtime ([int]$pass.Groups['duplicateAlbums'].Value -ge 2) 'runtime_duplicate_albums_missing'

    Write-Output ($safe[0] + ' endpoints_checked=2 loopback_bindings_checked=2 wrapper_assertions=' + $assertions)
}
catch {
    $code = if ($_.Exception.Message -match '^FOLDER_ALBUM_RUNTIME_STOP:([a-z0-9_]{1,96})$') {
        [string]$Matches[1]
    } else {
        'unexpected_wrapper_failure'
    }
    Write-Output ('PRIVATE_FULL_FOLDER_ALBUM_RUNTIME=FAIL code=' + $code + ' assertions=' + $assertions + ' source_names_read=0 source_paths_read=0')
    $exitCode = 2
}

exit $exitCode
