[CmdletBinding()]
param()

# Executes the aggregate-only private full-library media verifier. The wrapper
# captures all child process output and allows through just the fixed summary
# grammar, so a runtime error cannot disclose private source information.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot 'infra\private-full\.env.piwigo.staging'
$runtimePath = Join-Path $PSScriptRoot 'private-full-media-runtime.php'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$assertions = 0
$exitCode = 0

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')

function Stop-PrivateFullMediaRuntime([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_FULL_MEDIA_RUNTIME_STOP:' + $Code)
}

function Assert-PrivateFullMediaRuntime([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-PrivateFullMediaRuntime $Code }
}

try {
    Assert-PrivateFullMediaRuntime (Test-Path -LiteralPath $wsl -PathType Leaf) 'wsl_unavailable'
    $envItem = Get-Item -LiteralPath $envPath -Force -ErrorAction Stop
    Assert-PrivateFullMediaRuntime (-not $envItem.PSIsContainer -and -not ($envItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'candidate_env_untrusted'
    $relativeEnv = $envItem.FullName.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relativeEnv
    Assert-PrivateFullMediaRuntime ($LASTEXITCODE -eq 0) 'candidate_env_not_ignored'
    Assert-PrivateFullMediaRuntime (@(& git -C $projectRoot ls-files -- $relativeEnv 2>$null).Count -eq 0) 'candidate_env_tracked'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envItem.FullName
    $assertions++

    $runtimeSource = [IO.File]::ReadAllText($runtimePath, [Text.UTF8Encoding]::new($false, $true))
    Assert-PrivateFullMediaRuntime ($runtimeSource.Contains('START TRANSACTION READ ONLY')) 'runtime_read_only_transaction_missing'
    Assert-PrivateFullMediaRuntime (-not ($runtimeSource -match '(?im)\b(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|OUTFILE)\b')) 'runtime_mutation_statement_detected'
    Assert-PrivateFullMediaRuntime (-not ($runtimeSource -match '(?i)(?:source_root|staging_path|relative_source_path|original_filename)')) 'runtime_private_field_read_detected'

    $composePrefix = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose',
        '--env-file', 'infra/private-full/.env.piwigo.staging',
        '-f', 'infra/docker-compose.yml',
        '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo'
    )
    $compose = $composePrefix + @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_FULL_MEDIA_FIXTURE=1',
        'piwigo', 'php', '/workspace/tests/phase3/private-full-media-runtime.php'
    )
    $raw = @(& $wsl @compose 2>&1)
    $processExit = $LASTEXITCODE
    $safe = @($raw | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^PRIVATE_FULL_MEDIA_RUNTIME=(?:PASS|FAIL)\b'
    })
    Assert-PrivateFullMediaRuntime ($processExit -eq 0 -and $safe.Count -eq 1) 'runtime_query_failed'
    $pass = [regex]::Match(
        $safe[0],
        '^PRIVATE_FULL_MEDIA_RUNTIME=PASS assertions=(?<assertions>\d+) originals=(?<originals>\d+) mode_0660_verified=(?<modes>\d+) checksum_sampled=(?<checksums>\d+) managed_reference_mode=CANONICAL_ONLY$'
    )
    Assert-PrivateFullMediaRuntime $pass.Success 'runtime_output_invalid'
    Assert-PrivateFullMediaRuntime ([int]$pass.Groups['assertions'].Value -gt 0) 'runtime_assertions_missing'
    Assert-PrivateFullMediaRuntime ([int]$pass.Groups['originals'].Value -gt 0) 'runtime_originals_missing'
    Assert-PrivateFullMediaRuntime ([int]$pass.Groups['modes'].Value -eq [int]$pass.Groups['originals'].Value) 'runtime_file_mode_policy_invalid'
    Assert-PrivateFullMediaRuntime ([int]$pass.Groups['checksums'].Value -eq [Math]::Min(64, [int]$pass.Groups['originals'].Value)) 'runtime_checksum_policy_invalid'
    Write-Output ($safe[0] + ' wrapper_assertions=' + $assertions)
}
catch {
    $code = if ($_.Exception.Message -match '^PRIVATE_FULL_MEDIA_RUNTIME_STOP:([a-z0-9_]{1,96})$') {
        [string]$Matches[1]
    } else {
        'unexpected_wrapper_failure'
    }
    Write-Output ('PRIVATE_FULL_MEDIA_RUNTIME=FAIL code=' + $code + ' assertions=' + $assertions)
    $exitCode = 2
}

exit $exitCode
