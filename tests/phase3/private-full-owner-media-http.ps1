[CmdletBinding()]
param()

# Owner-only MediaGuard deployment probe. It executes a read-only PHP test in
# the 8190 private-full Piwigo container and permits only a compact aggregate
# result through to the terminal. It never opens private source, staging, or
# import-manifest paths and never emits a media URL, filename, or id.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot 'infra\private-full\.env.piwigo.owner'
$envFile = 'infra/private-full/.env.piwigo.owner'
$runtimePath = Join-Path $PSScriptRoot 'private-full-owner-media-http.php'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$assertions = 0
$exitCode = 0

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function Stop-PrivateFullOwnerMediaHttp([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_FULL_OWNER_MEDIA_HTTP_STOP:' + $Code)
}

function Assert-PrivateFullOwnerMediaHttp([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-PrivateFullOwnerMediaHttp $Code }
}

try {
    Assert-PrivateFullOwnerMediaHttp (Test-Path -LiteralPath $wsl -PathType Leaf) 'wsl_unavailable'
    $envItem = Get-Item -LiteralPath $envPath -Force -ErrorAction Stop
    Assert-PrivateFullOwnerMediaHttp (-not $envItem.PSIsContainer -and -not ($envItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'owner_env_untrusted'
    $relativeEnv = $envItem.FullName.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relativeEnv
    Assert-PrivateFullOwnerMediaHttp ($LASTEXITCODE -eq 0) 'owner_env_not_ignored'
    Assert-PrivateFullOwnerMediaHttp (@(& git -C $projectRoot ls-files -- $relativeEnv 2>$null).Count -eq 0) 'owner_env_tracked'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envItem.FullName
    $assertions++

    $runtimeSource = [IO.File]::ReadAllText($runtimePath, [Text.UTF8Encoding]::new($false, $true))
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains('START TRANSACTION READ ONLY')) 'runtime_read_only_transaction_missing'
    Assert-PrivateFullOwnerMediaHttp (-not ($runtimeSource -match '(?im)\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|OUTFILE)\b')) 'runtime_mutation_statement_detected'
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains("tcp://127.0.0.1:80")) 'runtime_loopback_target_missing'
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains("['GET', 'HEAD', 'RANGE']")) 'runtime_method_matrix_missing'
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains("'ORIGINAL'") -and $runtimeSource.Contains("'DERIVATIVE'")) 'runtime_surface_matrix_missing'
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains('status === 403')) 'runtime_guest_deny_missing'
    Assert-PrivateFullOwnerMediaHttp ($runtimeSource.Contains('direct_guest_requests=')) 'runtime_safe_summary_missing'
    Assert-PrivateFullOwnerMediaHttp (-not ($runtimeSource -match '(?i)(?:source_root|staging_path|relative_source_path|original_filename|absolute_path)')) 'runtime_private_field_read_detected'

    $compose = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose',
        '--env-file', $envFile,
        '-f', 'infra/docker-compose.yml',
        '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo',
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_FULL_OWNER_MEDIA_HTTP=1',
        'piwigo', 'php', '/workspace/tests/phase3/private-full-owner-media-http.php'
    )
    $raw = @(& $wsl @compose 2>&1)
    $processExit = $LASTEXITCODE
    $safe = @($raw | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^PRIVATE_FULL_OWNER_MEDIA_HTTP=(?:PASS|FAIL)\b'
    })
    Assert-PrivateFullOwnerMediaHttp ($processExit -eq 0 -and $safe.Count -eq 1) 'runtime_query_failed'
    $pass = [regex]::Match(
        $safe[0],
        '^PRIVATE_FULL_OWNER_MEDIA_HTTP=PASS assertions=(?<assertions>\d+) direct_guest_requests=(?<requests>\d+) methods=GET_HEAD_RANGE surfaces=ORIGINAL_DERIVATIVE scope=OWNER_8190$'
    )
    Assert-PrivateFullOwnerMediaHttp $pass.Success 'runtime_output_invalid'
    Assert-PrivateFullOwnerMediaHttp ([int]$pass.Groups['assertions'].Value -gt 0) 'runtime_assertions_missing'
    Assert-PrivateFullOwnerMediaHttp ([int]$pass.Groups['requests'].Value -eq 6) 'runtime_guest_request_matrix_invalid'
    Write-Output ($safe[0] + ' wrapper_assertions=' + $assertions)
}
catch {
    $code = if ($_.Exception.Message -match '^PRIVATE_FULL_OWNER_MEDIA_HTTP_STOP:([a-z0-9_]{1,96})$') {
        [string]$Matches[1]
    } else {
        'unexpected_wrapper_failure'
    }
    Write-Output ('PRIVATE_FULL_OWNER_MEDIA_HTTP=FAIL code=' + $code + ' assertions=' + $assertions)
    $exitCode = 2
}

exit $exitCode
