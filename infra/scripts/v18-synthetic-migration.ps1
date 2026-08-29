<#!
.SYNOPSIS
Drives the fixed, synthetic-only V17 -> V18 ClassIdentity migration proof.

.DESCRIPTION
The only accepted input is the existing, verified public 72-photo V16 DB-only
snapshot.  This runner creates one new attempt8 Docker project with fixed
volumes, fixed internal bridges and loopback-only ports 9190/9191.  It never
accepts an Owner/runtime/source-media path, never cleans a failed lab, and
never reaches 8091, 8191, 8291, or a prior attempt.

The exact historical V17 Schema source is extracted from the pinned local Git
commit into an ignored staging path and SHA-256 verified.  It is used only to
create the controlled non-empty V17 database before current V18 code migrates
that independent database.  No historical code is installed into Piwigo.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('initialize', 'restore', 'bootstrap-v17', 'migrate', 'verify', 'recover', 'status')]
    [string]$Action = 'status',
    [ValidateSet('attempt8', 'attempt9', 'attempt10', 'attempt11', 'attempt12', 'attempt13', 'attempt14', 'attempt15', 'attempt16', 'attempt17', 'attempt18', 'attempt19', 'attempt20', 'attempt21')]
    [string]$Attempt = 'attempt8',
    [switch]$ResumeEmptyBootstrap,
    [switch]$ResumeEmptyRecovery,
    [switch]$ResumeRestoredRecovery,
    [switch]$ConfirmSyntheticRestore,
    [switch]$ConfirmSyntheticMigration,
    [switch]$ConfirmSyntheticRecovery
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wsl = "$env:SystemRoot\System32\wsl.exe"
$attemptSpec = switch ($Attempt) {
    'attempt8' {
        @{
            HttpPort = '9190'; CompatPort = '9191'
            AppSubnet = '10.255.3.0/24'; GatewaySubnet = '10.246.0.0/16'
            BffGatewayIp = '10.246.0.10'
        }
    }
    'attempt9' {
        # attempt8 remains a preserved, failed-before-container forensic lab.
        # attempt9 is the one additional fixed synthetic-only lab permitted for
        # the repaired runtime proof; it cannot share a network or volume name.
        @{
            HttpPort = '9290'; CompatPort = '9291'
            AppSubnet = '10.255.4.0/24'; GatewaySubnet = '10.246.1.0/16'
            BffGatewayIp = '10.246.1.10'
        }
    }
    'attempt10' {
        # attempt8 and attempt9 are preserved failed-before-container labs.
        # attempt10 uses the next independently allocated synthetic bridges.
        @{
            HttpPort = '9390'; CompatPort = '9391'
            AppSubnet = '10.255.5.0/24'; GatewaySubnet = '10.244.0.0/16'
            BffGatewayIp = '10.244.0.10'
        }
    }
    'attempt11' {
        # Attempts 8-10 remain preserved forensic labs. attempt11 is a new,
        # empty fixed lab after the runner's native-process and probe fixes.
        @{
            HttpPort = '9490'; CompatPort = '9491'
            AppSubnet = '10.255.6.0/24'; GatewaySubnet = '10.242.0.0/16'
            BffGatewayIp = '10.242.0.10'
        }
    }
    'attempt12' {
        # Attempts 8-11 are preserved forensic labs. attempt12 is the final
        # clean laboratory with the corrected V17-domain/ledger fingerprint.
        @{
            HttpPort = '9690'; CompatPort = '9691'
            AppSubnet = '10.255.7.0/24'; GatewaySubnet = '10.238.0.0/16'
            BffGatewayIp = '10.238.0.10'
        }
    }
    'attempt13' {
        # Attempts 8-12 remain preserved forensic labs. attempt13 was reserved
        # for the direct current-source proof, but Docker recovery left its
        # stopped containers/volumes intact before a DB restore. It remains
        # preserved and cannot be reused.
        @{
            HttpPort = '9790'; CompatPort = '9791'
            AppSubnet = '10.255.8.0/24'; GatewaySubnet = '10.236.0.0/16'
            BffGatewayIp = '10.236.0.10'
        }
    }
    'attempt14' {
        # attempt14 is the one new, empty direct V16 -> V18 laboratory after
        # preserving attempt13's interrupted Docker-recovery state. It does
        # not bootstrap historical V17 code.
        @{
            HttpPort = '9890'; CompatPort = '9891'
            AppSubnet = '10.255.9.0/24'; GatewaySubnet = '10.234.0.0/16'
            BffGatewayIp = '10.234.0.10'
        }
    }
    'attempt15' {
        # attempt14 remains preserved with its successful direct runtime proof
        # and a rejected attestation generated before canonical source-entry
        # sorting was repaired. attempt15 is the next fresh direct V16 -> V18
        # laboratory; it shares no Docker project, volumes, bridge, or ports
        # with a prior attempt and does not bootstrap historical V17 code.
        @{
            HttpPort = '9990'; CompatPort = '9991'
            AppSubnet = '10.255.10.0/24'; GatewaySubnet = '10.232.0.0/16'
            BffGatewayIp = '10.232.0.10'
        }
    }
    'attempt16' {
        # attempt15 remains preserved after an interrupted V16 restore that
        # did not emit its terminal evidence before the local Docker lifecycle
        # stopped the lab. attempt16 is a fresh direct lab and is used only by
        # the bounded restore-and-prove chain below; it shares no prior state.
        @{
            HttpPort = '10090'; CompatPort = '10091'
            AppSubnet = '10.255.11.0/24'; GatewaySubnet = '10.230.0.0/16'
            BffGatewayIp = '10.230.0.10'
        }
    }
    'attempt17' {
        # Attempts 13-16 remain preserved forensic laboratories. attempt17 is
        # a new, empty, direct V16 -> V18 lab after the V4 Chrome acceptance
        # harness changed; it shares no Docker project, volumes, bridges, or
        # loopback ports with any earlier attempt.
        @{
            HttpPort = '10190'; CompatPort = '10191'
            AppSubnet = '10.255.12.0/24'; GatewaySubnet = '10.228.0.0/16'
            BffGatewayIp = '10.228.0.10'
        }
    }
    'attempt18' {
        # attempt17 is preserved after the host's implicit Get-FileHash module
        # autoload failed before any lab state could be created. attempt18 is
        # the next empty direct V16 -> V18 laboratory after that dependency is
        # made explicit. It shares no project, volumes, bridges, or ports with
        # any prior attempt.
        @{
            HttpPort = '10290'; CompatPort = '10291'
            AppSubnet = '10.255.13.0/24'; GatewaySubnet = '10.226.0.0/16'
            BffGatewayIp = '10.226.0.10'
        }
    }
    'attempt19' {
        # attempt18 is preserved after confirming that a child PowerShell
        # process needs module-qualified hashing rather than command discovery.
        # attempt19 is the next isolated direct V16 -> V18 laboratory with
        # that repair applied across every proof and owner-gate hash boundary.
        @{
            HttpPort = '10390'; CompatPort = '10391'
            AppSubnet = '10.255.14.0/24'; GatewaySubnet = '10.224.0.0/16'
            BffGatewayIp = '10.224.0.10'
        }
    }
    'attempt20' {
        # attempt19 is preserved after emitting a bounded nested-runner hash
        # failure. attempt20 is the next isolated direct V16 -> V18 laboratory
        # with separated module-import and hash-command diagnostics.
        @{
            HttpPort = '10490'; CompatPort = '10491'
            AppSubnet = '10.255.15.0/24'; GatewaySubnet = '10.222.0.0/16'
            BffGatewayIp = '10.222.0.10'
        }
    }
    'attempt21' {
        # attempt20 is preserved after a bounded Get-FileHash command failure.
        # attempt21 is the next isolated direct V16 -> V18 laboratory using
        # .NET BCL stream hashing, which does not depend on PowerShell modules.
        @{
            HttpPort = '10590'; CompatPort = '10591'
            AppSubnet = '10.255.16.0/24'; GatewaySubnet = '10.220.0.0/16'
            BffGatewayIp = '10.220.0.10'
        }
    }
}
$sandboxRoot = Join-Path $projectRoot ('.codex-work\v18-synthetic-migration-' + $Attempt)
$configRoot = Join-Path $sandboxRoot 'config'
$fixtureRoot = Join-Path $sandboxRoot 'fixtures'
$reportRoot = Join-Path $sandboxRoot 'reports'
$envPath = Join-Path $configRoot '.env.piwigo'
# The first attempt to stage this source deliberately remains preserved as a
# failed, no-Docker forensic artifact. The fixed writer uses a new exact file
# and never overwrites the earlier staging path.
$v17SchemaPath = Join-Path $fixtureRoot 'Schema-v17-52ff3a7-retry2.php'
$inputRoot = Join-Path $projectRoot '.codex-work\v4-synthetic-migration\input'
$composePath = 'infra/docker-compose.yml'
$overridePath = 'infra/v18-synthetic-migration/docker-compose.override.yml'
$projectName = 'class_archive_v18_synthetic_migration_' + $Attempt
$httpPort = [string]$attemptSpec.HttpPort
$compatPort = [string]$attemptSpec.CompatPort
$appNetwork = $projectName + '_app'
$appSubnet = [string]$attemptSpec.AppSubnet
$gatewayNetwork = $projectName + '_gateway'
$gatewaySubnet = [string]$attemptSpec.GatewaySubnet
$v17Commit = '52ff3a7ba91155efc7bed1572e2b1740973e484c'
$v17SchemaSha256 = 'aee8ced818747a8f81c816ef5aef112005af280b694ef3bdf8f7ac453e6f7413'
$script:stage = 'initialization'

function Stop-V18SyntheticMigration([string]$Code) {
    throw [InvalidOperationException]::new('V18_SYNTHETIC_MIGRATION_STOP:' + $Code)
}

function Write-V18SyntheticMigration([string]$State, [string]$Stage, [string]$Extra = '') {
    $suffix = if ([string]::IsNullOrWhiteSpace($Extra)) { '' } else { ' ' + $Extra }
    Write-Output ("V18_SYNTHETIC_MIGRATION={0} stage={1}{2}" -f $State, $Stage, $suffix)
}

function Assert-PathInside([string]$Path, [string]$Root, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not (($full + [IO.Path]::DirectorySeparatorChar).StartsWith($rootFull, [StringComparison]::OrdinalIgnoreCase))) {
        Stop-V18SyntheticMigration 'path_outside_allowed_root'
    }
    if ($MustExist -and -not (Test-Path -LiteralPath $full)) { Stop-V18SyntheticMigration 'required_path_missing' }
    $cursor = if (Test-Path -LiteralPath $full) { Get-Item -LiteralPath $full -Force } else { Get-Item -LiteralPath (Split-Path -Parent $full) -Force }
    while ($null -ne $cursor) {
        if (($cursor.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V18SyntheticMigration 'reparse_point_forbidden' }
        $cursor = if ($cursor -is [IO.DirectoryInfo]) { $cursor.Parent } else { $cursor.Directory }
    }
    return $full
}

function Assert-IgnoredUntracked([string]$Path, [bool]$Directory) {
    $full = Assert-PathInside $Path $projectRoot
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    if (($Directory -and -not $item.PSIsContainer) -or (-not $Directory -and $item.PSIsContainer)) {
        Stop-V18SyntheticMigration 'ignored_path_type_invalid'
    }
    $relative = $full.Substring($projectRoot.TrimEnd('\', '/').Length).TrimStart('\', '/').Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V18SyntheticMigration 'sandbox_path_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V18SyntheticMigration 'sandbox_path_tracked' }
    return $full
}

function Get-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $result = @(& $wsl -d Ubuntu --exec wslpath -a $full 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1) { Stop-V18SyntheticMigration 'wsl_path_conversion_failed' }
    $value = ([string]$result[0]).Trim()
    if ($value -notmatch '^/mnt/[a-z]/' -or $value.Contains('..') -or $value.Contains('//')) { Stop-V18SyntheticMigration 'wsl_path_invalid' }
    return $value
}

function New-SyntheticSecret([int]$Bytes = 36) {
    $buffer = [byte[]]::new($Bytes)
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return ([Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+','-').Replace('/','_'))
}

function Export-PinnedGitBlob([string]$Revision, [string]$RepositoryPath, [string]$Destination) {
    if (Test-Path -LiteralPath $Destination) { Stop-V18SyntheticMigration 'historical_schema_destination_exists' }
    $git = (Get-Command git.exe -ErrorAction Stop).Source
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $git
    $info.WorkingDirectory = $projectRoot
    $info.Arguments = ('show --no-textconv "' + $Revision + ':' + $RepositoryPath + '"')
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V18SyntheticMigration 'historical_schema_extract_start_failed' }
    try {
        $errorTask = $process.StandardError.ReadToEndAsync()
        $target = [IO.File]::Open($Destination, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
        try {
            $process.StandardOutput.BaseStream.CopyTo($target)
        } finally {
            $target.Dispose()
        }
        $process.WaitForExit()
        $stderr = $errorTask.GetAwaiter().GetResult()
        if ($process.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace($stderr)) { Stop-V18SyntheticMigration 'historical_schema_extract_failed' }
    } finally {
        $process.Dispose()
    }
}

function Get-InputSnapshot {
    Assert-IgnoredUntracked $inputRoot $true | Out-Null
    $entries = @(Get-ChildItem -LiteralPath $inputRoot -Force -ErrorAction Stop)
    $allowedName = '^pre-migration-db-v16-to-v17-[0-9]{8}T[0-9]{6}Z$'
    $bundle = @($entries | Where-Object { $_.PSIsContainer -and $_.Name -match $allowedName })
    if ($bundle.Count -ne 1 -or $entries.Count -ne 1) { Stop-V18SyntheticMigration 'input_bundle_not_exactly_one' }
    $path = Assert-IgnoredUntracked $bundle[0].FullName $true
    $allowed = @('COMPLETE','MANIFEST.json','SHA256SUMS','database.sql.gz')
    $files = @(Get-ChildItem -LiteralPath $path -Force -ErrorAction Stop)
    if ($files.Count -ne $allowed.Count -or @($files | Where-Object { $_.Name -notin $allowed -or $_.PSIsContainer -or ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0 }).Count -ne 0) {
        Stop-V18SyntheticMigration 'input_bundle_file_set_invalid'
    }
    $manifest = Get-Content -LiteralPath (Join-Path $path 'MANIFEST.json') -Raw | ConvertFrom-Json -ErrorAction Stop
    if ($manifest.format -ne 1 -or $manifest.scope -ne 'DB_ONLY_PRE_MIGRATION_ROLLBACK' -or $manifest.schema_current -ne 16 -or $manifest.schema_from -ne 16 -or $manifest.schema_to -ne 17 -or $manifest.media -ne 'NOT_INCLUDED' -or $manifest.dump_file -ne 'database.sql.gz' -or [string]$manifest.dump_sha256 -notmatch '^[a-f0-9]{64}$') {
        Stop-V18SyntheticMigration 'input_bundle_manifest_invalid'
    }
    $dumpHash = Get-FileSha256 (Join-Path $path 'database.sql.gz')
    if (-not [string]::Equals($dumpHash, [string]$manifest.dump_sha256, [StringComparison]::Ordinal)) { Stop-V18SyntheticMigration 'input_bundle_hash_invalid' }
    return @{ Path = $path; Name = $bundle[0].Name }
}

function Get-FileSha256([string]$Path) {
    # Use the platform BCL directly so isolated no-profile child PowerShell
    # runs do not depend on command discovery or module import behavior.
    try {
        $stream = [IO.File]::Open($Path, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::Read)
        try {
            $algorithm = [Security.Cryptography.SHA256]::Create()
            try { $bytes = $algorithm.ComputeHash($stream) }
            finally { $algorithm.Dispose() }
        }
        finally { $stream.Dispose() }
        $hash = [BitConverter]::ToString($bytes).Replace('-','')
    }
    catch { Stop-V18SyntheticMigration 'file_hash_runtime_failed' }
    if ([string]$hash -notmatch '^[a-fA-F0-9]{64}$') { Stop-V18SyntheticMigration 'file_hash_result_invalid' }
    return ([string]$hash).ToLowerInvariant()
}

function Get-VolumeName([string]$Logical) {
    if ($Logical -notmatch '^[a-z0-9_]+$') { Stop-V18SyntheticMigration 'volume_name_invalid' }
    return $projectName + '_' + $Logical
}

function Get-ExpectedValues([hashtable]$Snapshot) {
    return [ordered]@{
        COMPOSE_PROJECT_NAME = $projectName
        CLASS_ARCHIVE_HTTP_PORT = $httpPort
        CLASS_ARCHIVE_COMPAT_HTTP_PORT = $compatPort
        CLASS_ARCHIVE_CORE_PUBLIC_PORT = $httpPort
        CLASS_ARCHIVE_BASE_URL = "http://127.0.0.1:$httpPort"
        CLASS_ARCHIVE_TIMEZONE = 'Asia/Shanghai'
        CLASS_ARCHIVE_V18_SANDBOX_APP_NETWORK = $appNetwork
        CLASS_ARCHIVE_V18_SANDBOX_APP_SUBNET = $appSubnet
        CLASS_ARCHIVE_GATEWAY_NETWORK = $gatewayNetwork
        CLASS_ARCHIVE_V18_GATEWAY_NETWORK = $gatewayNetwork
        CLASS_ARCHIVE_V18_GATEWAY_SUBNET = $gatewaySubnet
        CLASS_ARCHIVE_BFF_GATEWAY_IP = [string]$attemptSpec.BffGatewayIp
        V18_SYNTHETIC_SNAPSHOT_PATH = Get-WslPath ($Snapshot['Path'])
        V18_SYNTHETIC_V17_SCHEMA_PATH = Get-WslPath ($v17SchemaPath)
        PIWIGO_UID = '1000'
        PIWIGO_GID = '1000'
        PIWIGO_DATA_VOLUME = Get-VolumeName 'piwigo_data'
        PIWIGO_UPLOADS_VOLUME = Get-VolumeName 'piwigo_uploads'
        PIWIGO_GALLERIES_VOLUME = Get-VolumeName 'piwigo_galleries'
        PIWIGO_DERIVATIVES_VOLUME = Get-VolumeName 'piwigo_derivatives'
        PIWIGO_DB_VOLUME = Get-VolumeName 'piwigo_db'
        PIWIGO_SCRIPTS_VOLUME = Get-VolumeName 'piwigo_scripts'
        PIWIGO_BACKUPS_VOLUME = Get-VolumeName 'backups'
        V18_SYNTHETIC_RECOVERY_DB_VOLUME = Get-VolumeName 'v18_recovery_db'
        PIWIGO_IMAGE = 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
        MARIADB_IMAGE = 'mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf'
        DB_NAME = 'piwigo'
        DB_USER = 'piwigo'
        PIWIGO_ADMIN_USERNAME = 'synthetic-v18-sandbox-admin'
        PIWIGO_ADMIN_EMAIL = 'synthetic-v18-sandbox@class-archive.local'
        SMTP_HOST = ''
        SMTP_PORT = ''
        SMTP_USERNAME = ''
        SMTP_PASSWORD = ''
        SMTP_ENCRYPTION = ''
    }
}

function Initialize-V18Sandbox([hashtable]$Snapshot) {
    if ((Test-Path -LiteralPath $envPath) -or (Test-Path -LiteralPath $v17SchemaPath)) { Stop-V18SyntheticMigration 'sandbox_already_initialized' }
    foreach ($directory in @($sandboxRoot,$configRoot,$fixtureRoot,$reportRoot)) {
        if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force | Out-Null }
        Assert-IgnoredUntracked $directory $true | Out-Null
    }
    Export-PinnedGitBlob -Revision $v17Commit -RepositoryPath 'plugins/ClassIdentity/src/Schema.php' -Destination $v17SchemaPath
    $digest = Get-FileSha256 $v17SchemaPath
    if (-not [string]::Equals($digest, $v17SchemaSha256, [StringComparison]::Ordinal)) { Stop-V18SyntheticMigration 'historical_schema_hash_invalid' }
    $values = Get-ExpectedValues -Snapshot $Snapshot
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($pair in $values.GetEnumerator()) { [void]$lines.Add($pair.Key + '=' + [string]$pair.Value) }
    [void]$lines.Add('DB_PASSWORD=' + (New-SyntheticSecret))
    [void]$lines.Add('DB_ROOT_PASSWORD=' + (New-SyntheticSecret))
    [void]$lines.Add('CLASS_ARCHIVE_CLAIM_CODE_PEPPER=' + (New-SyntheticSecret 48))
    [void]$lines.Add('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=' + (New-SyntheticSecret 48))
    [IO.File]::WriteAllText($envPath, (($lines -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
    & icacls.exe $envPath /inheritance:r /grant:r "${env:USERNAME}:(R,W)" | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-V18SyntheticMigration 'sandbox_env_acl_failed' }
    Write-V18SyntheticMigration 'READY' 'initialize' ('attempt=' + $Attempt + ' source=V16_DB_ONLY historical_schema=V17_PINNED media=NOT_MOUNTED')
}

function Assert-Initialized([hashtable]$Snapshot) {
    Assert-IgnoredUntracked $sandboxRoot $true | Out-Null
    Assert-IgnoredUntracked $configRoot $true | Out-Null
    Assert-IgnoredUntracked $fixtureRoot $true | Out-Null
    Assert-IgnoredUntracked $reportRoot $true | Out-Null
    Assert-IgnoredUntracked $envPath $false | Out-Null
    Assert-IgnoredUntracked $v17SchemaPath $false | Out-Null
    $digest = Get-FileSha256 $v17SchemaPath
    if (-not [string]::Equals($digest, $v17SchemaSha256, [StringComparison]::Ordinal)) { Stop-V18SyntheticMigration 'historical_schema_hash_invalid' }
    # Windows PowerShell 5 defaults Get-Content to the local ANSI code page when
    # a UTF-8 file has no BOM. The project path includes Chinese characters, so
    # use the exact encoding used by Initialize-V18Sandbox for the contract file.
    $contents = [IO.File]::ReadAllText($envPath, [Text.UTF8Encoding]::new($false))
    if ($contents.Contains("`0")) { Stop-V18SyntheticMigration 'sandbox_env_invalid' }
    $values = @{}
    foreach ($line in ($contents -split "`r?`n")) {
        if ($line -eq '') { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-V18SyntheticMigration 'sandbox_env_line_invalid' }
        $key = [string]$Matches[1]
        if ($values.ContainsKey($key)) { Stop-V18SyntheticMigration 'sandbox_env_duplicate_key' }
        $values[$key] = [string]$Matches[2]
    }
    if (-not $values.ContainsKey('COMPOSE_PROJECT_NAME') -or [string]$values['COMPOSE_PROJECT_NAME'] -ne $projectName) { Stop-V18SyntheticMigration 'sandbox_env_invalid' }
    # Do not enumerate an OrderedDictionary directly in the comparison expression.
    # PowerShell can unwrap it differently between host versions, which would turn
    # a value comparison into an object-string comparison. Materialise it once and
    # iterate its keys explicitly so the sandbox contract remains deterministic.
    $expectedValues = Get-ExpectedValues -Snapshot $Snapshot
    foreach ($expectedKey in @($expectedValues.Keys)) {
        $key = [string]$expectedKey
        $expectedValue = [string]$expectedValues[$expectedKey]
        if (-not $values.ContainsKey($key)) {
            Stop-V18SyntheticMigration ('sandbox_env_value_invalid_' + $key.ToLowerInvariant() + '_missing')
        }
        $actualValue = [string]$values[$key]
        if (-not [string]::Equals($actualValue, $expectedValue, [StringComparison]::Ordinal)) {
            $actualDigest = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($actualValue))).Replace('-','').Substring(0,8).ToLowerInvariant()
            $expectedDigest = [BitConverter]::ToString([Security.Cryptography.SHA256]::Create().ComputeHash([Text.Encoding]::UTF8.GetBytes($expectedValue))).Replace('-','').Substring(0,8).ToLowerInvariant()
            Stop-V18SyntheticMigration ('sandbox_env_value_invalid_' + $key.ToLowerInvariant() + '_' + $actualDigest + '_' + $expectedDigest)
        }
    }
}

function Invoke-V18Compose([string[]]$Arguments, [switch]$Capture) {
    $wslRoot = Get-WslPath $projectRoot
    $wslEnv = Get-WslPath $envPath
    $all = @('-d','Ubuntu','--cd',$wslRoot,'--exec','docker','compose','--env-file',$wslEnv,'-f',$composePath,'-f',$overridePath) + $Arguments
    # docker compose writes normal progress lines to stderr. Windows
    # PowerShell can turn those benign progress records into terminating errors
    # before $LASTEXITCODE is available. Use ProcessStartInfo so only the native
    # process exit code decides success. All arguments are fixed runner values,
    # generated safe bundle identifiers, or validated WSL paths; reject any
    # argument that would require Windows command-line quoting.
    $nativeArgs = [System.Collections.Generic.List[string]]::new()
    foreach ($argument in $all) {
        $value = [string]$argument
        if ($value -match '[\s\"]' -or $value.Contains("`0")) { Stop-V18SyntheticMigration 'compose_argument_invalid' }
        [void]$nativeArgs.Add($value)
    }
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $wsl
    $info.Arguments = $nativeArgs -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V18SyntheticMigration 'compose_process_start_failed' }
    try {
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()
        $process.WaitForExit()
        $stdout = $stdoutTask.GetAwaiter().GetResult()
        $stderr = $stderrTask.GetAwaiter().GetResult()
        $exitCode = $process.ExitCode
    } finally {
        $process.Dispose()
    }
    $lines = @($stdout -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($exitCode -ne 0) { Stop-V18SyntheticMigration ('compose_failed_' + $script:stage) }
    if ($Capture) { return $lines }
    return @()
}

function Assert-FreshSyntheticAttempt {
    $volumes = @(& $wsl -d Ubuntu --exec docker volume ls --format '{{.Name}}' 2>$null)
    $networks = @(& $wsl -d Ubuntu --exec docker network ls --format '{{.Name}}' 2>$null)
    $containers = @(& $wsl -d Ubuntu --exec docker ps -a --filter ('label=com.docker.compose.project=' + $projectName) --format '{{.Names}}' 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V18SyntheticMigration 'docker_inventory_failed' }
    $expectedVolumes = @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts','backups','v18_recovery_db' | ForEach-Object { Get-VolumeName $_ })
    $existingVolumes = @($expectedVolumes | Where-Object { $_ -in $volumes })
    $existingContainers = @($containers | Where-Object { $_ -ne '' })
    $networkPresent = ($appNetwork -in $networks -or $gatewayNetwork -in $networks)
    if ($existingVolumes.Count -eq 0 -and -not $networkPresent -and $existingContainers.Count -eq 0) { return }

    # A native PowerShell stderr-handling defect can leave only the empty
    # bootstrap containers behind before any V16 data restore. Preserve that
    # lab rather than deleting it. The explicit switch permits one bounded
    # continuation only after proving that it remains database-empty and has
    # no reports or recovery target. Any other existing state is denied.
    if (-not $ResumeEmptyBootstrap -or $Attempt -ne 'attempt11') {
        Stop-V18SyntheticMigration ($Attempt + '_not_empty_preserved')
    }
    $coreVolumes = @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts' | ForEach-Object { Get-VolumeName $_ })
    $expectedContainers = @(
        ($projectName + '-db-1')
        ($projectName + '-piwigo-1')
    )
    if ($existingVolumes.Count -ne $coreVolumes.Count) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_volume_count_invalid' }
    if (@($coreVolumes | Where-Object { $_ -notin $existingVolumes }).Count -ne 0) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_volume_set_invalid' }
    if ($appNetwork -notin $networks -or $gatewayNetwork -notin $networks) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_network_set_invalid' }
    if ($existingContainers.Count -ne $expectedContainers.Count) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_container_count_invalid' }
    if (@($expectedContainers | Where-Object { $_ -notin $existingContainers }).Count -ne 0) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_container_set_invalid' }
    if (@(Get-ChildItem -LiteralPath $reportRoot -Force -ErrorAction Stop).Count -ne 0) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_reports_present' }
    if ((Get-V18TableCount) -ne 0) { Stop-V18SyntheticMigration 'resume_empty_bootstrap_database_not_empty' }
}

function Wait-V18Service([string]$Service, [string]$Expected = 'healthy', [int]$Seconds = 60) {
    for ($i = 0; $i -lt $Seconds; ++$i) {
        $lines = @(Invoke-V18Compose @('ps','--format','json',$Service) -Capture)
        if ($lines.Count -eq 1) {
            try {
                $item = $lines[0] | ConvertFrom-Json -ErrorAction Stop
                if ([string]$item.Health -eq $Expected -or ([string]$item.State -eq $Expected)) { return }
            } catch { }
        }
        Start-Sleep -Seconds 1
    }
    Stop-V18SyntheticMigration ('service_not_' + $Expected + '_' + $Service)
}

function Get-V18SchemaVersion {
    $lines = @(Invoke-V18Compose @('exec','-T','db','sh','/workspace/infra/scripts/v18-synthetic-db-probe.sh','schema') -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(16|17|18)$') { Stop-V18SyntheticMigration 'schema_probe_invalid' }
    return [int]$lines[0]
}

function Get-V18TableCount {
    $lines = @(Invoke-V18Compose @('exec','-T','db','sh','/workspace/infra/scripts/v18-synthetic-db-probe.sh','table-count') -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^[0-9]+$') { Stop-V18SyntheticMigration 'table_count_probe_invalid' }
    return [int]$lines[0]
}

function Invoke-Proof([string]$Mode) {
    $script:stage = $Mode.TrimStart('-')
    $lines = @(Invoke-V18Compose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/v18-synthetic-migration-proof.php',$Mode) -Capture)
    $record = @($lines | Where-Object { $_ -match '^V18_SYNTHETIC_PROOF=PASS ' })
    if ($record.Count -ne 1) { Stop-V18SyntheticMigration ('proof_evidence_invalid_' + $script:stage) }
    return [string]$record[0]
}

function Get-Field([string]$Record, [string]$Name) {
    $match = [regex]::Match($Record, ('(?:^|\s)' + [regex]::Escape($Name) + '=([^\s]+)'))
    if (-not $match.Success) { Stop-V18SyntheticMigration ('proof_field_missing_' + $Name) }
    return $match.Groups[1].Value
}

function Write-Report([string]$Name, [string]$Value) {
    $path = Join-Path $reportRoot $Name
    [IO.File]::WriteAllText($path, $Value + "`n", [Text.UTF8Encoding]::new($false))
    Assert-IgnoredUntracked $path $false | Out-Null
}

function Read-Report([string]$Name) {
    $path = Join-Path $reportRoot $Name
    Assert-IgnoredUntracked $path $false | Out-Null
    return (Get-Content -LiteralPath $path -Raw).Trim()
}

# V18 adds operationally mutable lifecycle checkpoints.  A companion hash is
# deliberately allowed to be introduced for an already-proven historical lab:
# it is derived only from a live proof that validates immutable V17 seed
# anchors or V18 schema/migration metadata, then written into the ignored
# attempt report.  It never rewrites the original full-state evidence.
function Get-OrCreateProofFingerprintReport([string]$Name, [string]$Record, [string]$Field) {
    $path = Join-Path $reportRoot $Name
    if (Test-Path -LiteralPath $path) { return Read-Report $Name }
    $value = Get-Field $Record $Field
    if ($value -notmatch '^[a-f0-9]{64}$') { Stop-V18SyntheticMigration ('proof_fingerprint_invalid_' + $Field) }
    Write-Report $Name $value
    return $value
}

function Invoke-Restore([hashtable]$Snapshot) {
    if (-not $ConfirmSyntheticRestore) { Stop-V18SyntheticMigration 'synthetic_restore_confirmation_required' }
    Assert-FreshSyntheticAttempt
    $script:stage = 'restore_bootstrap'
    Invoke-V18Compose @('up','-d','db','piwigo') | Out-Null
    Wait-V18Service 'db'
    if ((Get-V18TableCount) -ne 0) { Stop-V18SyntheticMigration 'restore_target_not_empty' }
    Invoke-V18Compose @('stop','piwigo') | Out-Null
    $script:stage = 'restore_import'
    $lines = @(Invoke-V18Compose @('--profile','v18-synthetic-migration','run','--rm','v18-synthetic-db-restore-v16') -Capture)
    if (@($lines | Where-Object { $_ -eq 'V4_SYNTHETIC_DB_RESTORE=PASS schema=16 scope=DB_ONLY media=NOT_MOUNTED target=ISOLATED maintenance=FAIL_CLOSED' }).Count -ne 1) { Stop-V18SyntheticMigration 'restore_evidence_invalid' }
    Invoke-V18Compose @('up','-d','piwigo') | Out-Null
    Wait-V18Service 'piwigo' 'running'
    if ((Get-V18SchemaVersion) -ne 16) { Stop-V18SyntheticMigration 'restore_not_v16' }
    Write-V18SyntheticMigration 'PASS' 'restore' ('schema=16 source=' + $Snapshot.Name + ' target=' + $Attempt + ' media=NOT_MOUNTED')
}

function Invoke-BootstrapV17 {
    if (-not $ConfirmSyntheticMigration) { Stop-V18SyntheticMigration 'synthetic_migration_confirmation_required' }
    $sourceSchema = Get-V18SchemaVersion
    if ($sourceSchema -notin @(16,17)) { Stop-V18SyntheticMigration 'bootstrap_source_not_v16_or_v17' }
    $record = Invoke-Proof '--bootstrap-v17'
    if ($record -notmatch '^V18_SYNTHETIC_PROOF=PASS stage=bootstrap_v17 schema=17 ' -or
        (Get-Field $record 'snapshots') -ne '8' -or (Get-Field $record 'pointers') -ne '8' -or
        (Get-Field $record 'items') -ne '8' -or (Get-Field $record 'maintenance') -ne '2' -or
        (Get-Field $record 'historical_commit') -ne $v17Commit) { Stop-V18SyntheticMigration 'bootstrap_evidence_invalid' }
    $bootstrapMode = Get-Field $record 'bootstrap_mode'
    if (($sourceSchema -eq 16 -and $bootstrapMode -ne 'MIGRATED') -or ($sourceSchema -eq 17 -and $bootstrapMode -ne 'RESUMED_CONTROLLED')) {
        Stop-V18SyntheticMigration 'bootstrap_transition_evidence_invalid'
    }
    $fingerprint = Get-Field $record 'v17_fingerprint'
    $ledgerFingerprint = Get-Field $record 'v17_ledger_fingerprint'
    $seedFingerprint = Get-Field $record 'v17_seed_fingerprint'
    if ($fingerprint -notmatch '^[a-f0-9]{64}$' -or $ledgerFingerprint -notmatch '^[a-f0-9]{64}$' -or $seedFingerprint -notmatch '^[a-f0-9]{64}$' -or (Get-V18SchemaVersion) -ne 17) { Stop-V18SyntheticMigration 'bootstrap_v17_invalid' }
    Write-Report 'v17-fingerprint.txt' $fingerprint
    Write-Report 'v17-ledger-fingerprint.txt' $ledgerFingerprint
    Write-Report 'v17-seed-fingerprint.txt' $seedFingerprint
    Write-V18SyntheticMigration 'PASS' 'bootstrap-v17' ('schema_from=' + $sourceSchema + ' schema=17 snapshots=8 pointers=8 items=8 maintenance=2 historical_source=PINNED bootstrap=' + $bootstrapMode)
}

function Invoke-MigrateV18 {
    if (-not $ConfirmSyntheticMigration) { Stop-V18SyntheticMigration 'synthetic_migration_confirmation_required' }
    $sourceSchema = Get-V18SchemaVersion
    if ($sourceSchema -notin @(17,18)) { Stop-V18SyntheticMigration 'migration_source_not_v17_or_v18' }
    $beforeLedger = Read-Report 'v17-ledger-fingerprint.txt'
    $first = Invoke-Proof '--migrate-v18'
    if ($sourceSchema -eq 17 -and $first -notmatch 'stage=migrate_v18 schema_from=17 schema_to=18 replay=NOT_APPLICABLE ') { Stop-V18SyntheticMigration 'migration_first_transition_evidence_invalid' }
    if ($sourceSchema -eq 18 -and $first -notmatch 'stage=migrate_v18 schema_from=18 schema_to=18 replay=PASS ') { Stop-V18SyntheticMigration 'migration_resume_ledger_evidence_invalid' }
    if ($sourceSchema -eq 17 -and (Get-Field $first 'v17_fingerprint') -ne (Read-Report 'v17-fingerprint.txt')) { Stop-V18SyntheticMigration 'migration_v17_fingerprint_changed' }
    $seedFingerprint = Get-OrCreateProofFingerprintReport 'v17-seed-fingerprint.txt' $first 'v17_seed_fingerprint'
    $migrationFingerprint = Get-OrCreateProofFingerprintReport 'v18-migration-fingerprint.txt' $first 'v18_fingerprint'
    if ((Get-Field $first 'v17_seed_fingerprint') -ne $seedFingerprint -or (Get-Field $first 'v17_ledger_fingerprint') -ne $beforeLedger -or (Get-Field $first 'v18_fingerprint') -ne $migrationFingerprint) { Stop-V18SyntheticMigration 'migration_stable_anchor_changed' }
    if ((Get-V18SchemaVersion) -ne 18) { Stop-V18SyntheticMigration 'migration_target_not_v18' }
    $second = Invoke-Proof '--migrate-v18'
    if ($second -notmatch 'stage=migrate_v18 schema_from=18 schema_to=18 replay=PASS ' -or (Get-Field $second 'v17_seed_fingerprint') -ne $seedFingerprint -or (Get-Field $second 'v17_ledger_fingerprint') -ne $beforeLedger -or (Get-Field $second 'v18_fingerprint') -ne $migrationFingerprint) { Stop-V18SyntheticMigration 'migration_replay_not_idempotent' }
    $transition = if ($sourceSchema -eq 17) { 'MIGRATED' } else { 'LEDGER_VERIFIED_RESUME' }
    Write-V18SyntheticMigration 'PASS' 'migrate' ('schema_from=' + $sourceSchema + ' schema_to=18 transition=' + $transition + ' v17_seed=UNCHANGED idempotent_replay=PASS media=NOT_MOUNTED')
}

function Invoke-VerifyV18 {
    if ((Get-V18SchemaVersion) -ne 18) { Stop-V18SyntheticMigration 'verify_schema_not_v18' }
    $record = Invoke-Proof '--verify-v18'
    $rotationRows = Get-Field $record 'rotation_rows'
    $rotationState = Get-Field $record 'rotation_state'
    $seedFingerprint = Get-OrCreateProofFingerprintReport 'v17-seed-fingerprint.txt' $record 'v17_seed_fingerprint'
    $migrationFingerprint = Get-OrCreateProofFingerprintReport 'v18-migration-fingerprint.txt' $record 'v18_fingerprint'
    if ($record -notmatch 'stage=verify_v18 schema=18 ' -or (Get-Field $record 'v17_seed_fingerprint') -ne $seedFingerprint -or (Get-Field $record 'v17_ledger_fingerprint') -ne (Read-Report 'v17-ledger-fingerprint.txt') -or (Get-Field $record 'v18_fingerprint') -ne $migrationFingerprint -or $rotationRows -notmatch '^[0-2]$' -or $rotationState -notin @('EMPTY','OPERATIONAL')) { Stop-V18SyntheticMigration 'verify_fingerprint_invalid' }
    $failClosed = Invoke-Proof '--fail-closed'
    if ($failClosed -ne 'V18_SYNTHETIC_PROOF=PASS stage=fail_closed unknown_schema=DENY scratch=DISPOSED') { Stop-V18SyntheticMigration 'unknown_state_not_fail_closed' }
    Write-V18SyntheticMigration 'PASS' 'verify' ('schema=18 v17_seed=UNCHANGED rotation_state=' + $rotationState + ' unknown_state=DENY media=NOT_MOUNTED')
}

function Invoke-RecoveryV18 {
    if (-not $ConfirmSyntheticRecovery) { Stop-V18SyntheticMigration 'synthetic_recovery_confirmation_required' }
    if (($ResumeEmptyRecovery -or $ResumeRestoredRecovery) -and $Attempt -ne 'attempt12') { Stop-V18SyntheticMigration 'resume_recovery_attempt_invalid' }
    if ($ResumeEmptyRecovery -and $ResumeRestoredRecovery) { Stop-V18SyntheticMigration 'resume_recovery_mode_ambiguous' }
    Invoke-VerifyV18
    $recoveryVolume = Get-VolumeName 'v18_recovery_db'
    $existingVolumes = @(& $wsl -d Ubuntu --exec docker volume ls --format '{{.Name}}' 2>$null)
    if ($ResumeEmptyRecovery -or $ResumeRestoredRecovery) {
        if ($recoveryVolume -notin $existingVolumes -or -not (Test-Path -LiteralPath (Join-Path $reportRoot 'v18-recovery-bundle.txt'))) {
            Stop-V18SyntheticMigration 'resume_empty_recovery_precondition_invalid'
        }
        $bundle = Read-Report 'v18-recovery-bundle.txt'
        if ($bundle -notmatch '^class-archive-v18-synthetic-[0-9]{8}T[0-9]{6}Z$') { Stop-V18SyntheticMigration 'resume_empty_recovery_bundle_invalid' }
    } else {
        if ($recoveryVolume -in $existingVolumes) { Stop-V18SyntheticMigration 'recovery_target_already_present' }
        $script:stage = 'recovery_backup'
        $lines = @(Invoke-V18Compose @('--profile','v18-synthetic-recovery','run','--rm','v18-synthetic-db-backup') -Capture)
        $record = @($lines | Where-Object { $_ -match '^V18_SYNTHETIC_DB_BACKUP=PASS bundle=class-archive-v18-synthetic-[0-9]{8}T[0-9]{6}Z ' })
        if ($record.Count -ne 1) { Stop-V18SyntheticMigration 'recovery_backup_evidence_invalid' }
        $bundle = Get-Field ([string]$record[0]) 'bundle'
        Write-Report 'v18-recovery-bundle.txt' $bundle
        $script:stage = 'recovery_target'
        Invoke-V18Compose @('--profile','v18-synthetic-recovery','up','-d','v18-synthetic-recovery-db') | Out-Null
        Wait-V18Service 'v18-synthetic-recovery-db'
    }
    if (-not $ResumeRestoredRecovery) {
        $script:stage = 'recovery_restore'
        $restore = @(Invoke-V18Compose @('--profile','v18-synthetic-recovery','run','--rm','-e',('CLASS_ARCHIVE_V18_SYNTHETIC_RESTORE_BUNDLE=' + $bundle),'v18-synthetic-db-restore') -Capture)
        if (@($restore | Where-Object { $_ -eq 'V18_SYNTHETIC_DB_RESTORE=PASS format=10 schema=18 scope=DB_ONLY target=SECOND_EMPTY_DB media=NOT_MOUNTED media_guard=NOT_CLAIMED photos=72' }).Count -ne 1) { Stop-V18SyntheticMigration 'recovery_restore_evidence_invalid' }
    }
    $script:stage = 'recovery_verify'
    $target = @(Invoke-V18Compose @('--profile','v18-synthetic-recovery','run','--rm','v18-synthetic-recovery-verify') -Capture)
    $targetRecord = @($target | Where-Object { $_ -match '^V18_SYNTHETIC_PROOF=PASS stage=verify_v18 schema=18 ' })
    if ($targetRecord.Count -ne 1) { Stop-V18SyntheticMigration 'recovery_fixture_mismatch' }
    $targetProof = [string]$targetRecord[0]
    $seedFingerprint = Get-OrCreateProofFingerprintReport 'v17-seed-fingerprint.txt' $targetProof 'v17_seed_fingerprint'
    $migrationFingerprint = Get-OrCreateProofFingerprintReport 'v18-migration-fingerprint.txt' $targetProof 'v18_fingerprint'
    if ((Get-Field $targetProof 'v17_seed_fingerprint') -ne $seedFingerprint -or (Get-Field $targetProof 'v17_ledger_fingerprint') -ne (Read-Report 'v17-ledger-fingerprint.txt') -or (Get-Field $targetProof 'v18_fingerprint') -ne $migrationFingerprint) { Stop-V18SyntheticMigration 'recovery_fixture_mismatch' }
    $recoveryMode = if ($ResumeRestoredRecovery) { 'RESTORED_TARGET_VERIFIED' } elseif ($ResumeEmptyRecovery) { 'EMPTY_TARGET_RESUMED' } else { 'FRESH' }
    Write-V18SyntheticMigration 'PASS' 'recover' ('format=10 schema=18 source_target_fixture=MATCH target=SECOND_EMPTY_DB mode=' + $recoveryMode + ' media=NOT_MOUNTED')
}

try {
    $snapshot = Get-InputSnapshot
    if ($Action -eq 'initialize') {
        Initialize-V18Sandbox $snapshot
        exit 0
    }
    Assert-Initialized $snapshot
    switch ($Action) {
        'restore' { Invoke-Restore $snapshot }
        'bootstrap-v17' { Invoke-BootstrapV17 }
        'migrate' { Invoke-MigrateV18 }
        'verify' { Invoke-VerifyV18 }
        'recover' { Invoke-RecoveryV18 }
        'status' {
            $schema = 'UNAVAILABLE'
            try { $schema = [string](Get-V18SchemaVersion) } catch { }
            Write-V18SyntheticMigration 'STATUS' 'status' ('attempt=' + $Attempt + ' schema=' + $schema + ' ports=127.0.0.1:' + $httpPort + '_' + $compatPort + ' media=NOT_MOUNTED')
        }
    }
} catch {
    $message = $_.Exception.Message
    $safe = [regex]::Replace($message, '[^A-Za-z0-9_:-]', '_')
    $line = [string]$_.InvocationInfo.ScriptLineNumber
    Write-Error ('V18_SYNTHETIC_MIGRATION=FAIL stage=' + $script:stage + ' code=' + $safe + ' line=' + $line)
    exit 1
}
