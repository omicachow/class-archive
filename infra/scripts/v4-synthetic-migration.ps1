<#!
.SYNOPSIS
Creates and drives the dedicated synthetic-only ClassIdentity v16 -> v17
migration laboratory used before any private/owner migration is considered.

.DESCRIPTION
The runner has no caller-supplied runtime, volume, port, source, owner, or
private-path selectors.  It accepts only the fixed DB-only snapshot directory
under .codex-work/v4-synthetic-migration/input, validates that snapshot was
created by the existing pre-migration mechanism, and uses an independent
Docker project, volumes, bridges and loopback ports 8490/8491.

It deliberately has no cleanup/down/delete action.  A failed sandbox remains
available for inspection and cannot affect 8091, 8191, 8291, or source media.
#>
[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'initialize', 'restore', 'migrate', 'verify', 'browser', 'status')]
    [string]$Action = 'validate',

    [switch]$ConfirmSyntheticRestore,
    [switch]$ConfirmSyntheticMigration,
    [switch]$ConfirmSyntheticBrowser,

    # A failed lab is preserved for forensic inspection. The fixed retry
    # laboratories below are independently addressed; callers cannot supply an
    # arbitrary project, port, network, volume, or snapshot path.
    [ValidateSet('primary', 'attempt2', 'attempt3', 'attempt4', 'attempt5', 'attempt6', 'attempt7')]
    [string]$Attempt = 'primary'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path

function Get-SandboxSpec([string]$Name) {
    switch ($Name) {
        'primary' {
            return @{
                name = 'primary'
                output_root = '.codex-work\v4-synthetic-migration'
                project_name = 'class_archive_v4_synthetic_migration'
                http_port = '8490'
                compat_port = '8491'
                app_network = 'class_archive_v4_synthetic_migration_app'
                app_subnet = '192.168.208.0/20'
                gateway_network = 'class_archive_v4_synthetic_migration_gateway'
                gateway_subnet = '10.249.0.0/16'
                bff_gateway_ip = '10.249.0.10'
            }
        }
        'attempt2' {
            return @{
                name = 'attempt2'
                output_root = '.codex-work\v4-synthetic-migration-attempt2'
                project_name = 'class_archive_v4_synthetic_migration_attempt2'
                http_port = '8590'
                compat_port = '8591'
                app_network = 'class_archive_v4_synthetic_migration_attempt2_app'
                app_subnet = '192.168.224.0/20'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt2_gateway'
                gateway_subnet = '10.250.0.0/16'
                bff_gateway_ip = '10.250.0.10'
            }
        }
        'attempt3' {
            return @{
                name = 'attempt3'
                output_root = '.codex-work\v4-synthetic-migration-attempt3'
                project_name = 'class_archive_v4_synthetic_migration_attempt3'
                http_port = '8690'
                compat_port = '8691'
                app_network = 'class_archive_v4_synthetic_migration_attempt3_app'
                app_subnet = '192.168.240.0/20'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt3_gateway'
                gateway_subnet = '10.251.0.0/16'
                bff_gateway_ip = '10.251.0.10'
            }
        }
        # attempt3 predates the file-backed DB probe required to avoid a
        # Windows PowerShell/WSL quoting fault. Preserve its restored v16
        # state untouched and use this new, fixed-address lab instead of
        # recreating or mutating an earlier forensic attempt.
        'attempt4' {
            return @{
                name = 'attempt4'
                output_root = '.codex-work\v4-synthetic-migration-attempt4'
                project_name = 'class_archive_v4_synthetic_migration_attempt4'
                http_port = '8790'
                compat_port = '8791'
                app_network = 'class_archive_v4_synthetic_migration_attempt4_app'
                app_subnet = '10.254.0.0/24'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt4_gateway'
                gateway_subnet = '10.252.0.0/16'
                bff_gateway_ip = '10.252.0.10'
            }
        }
        # attempt4's Docker default-address-pool allocation failed after its
        # isolated gateway bridge had already been created. Preserve that
        # partial lab too. attempt5 gives both bridges explicit, disjoint
        # subnets so Docker never needs to allocate from its exhausted pool.
        'attempt5' {
            return @{
                name = 'attempt5'
                output_root = '.codex-work\v4-synthetic-migration-attempt5'
                project_name = 'class_archive_v4_synthetic_migration_attempt5'
                http_port = '8890'
                compat_port = '8891'
                app_network = 'class_archive_v4_synthetic_migration_attempt5_app'
                app_subnet = '10.255.0.0/24'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt5_gateway'
                gateway_subnet = '10.253.0.0/16'
                bff_gateway_ip = '10.253.0.10'
            }
        }
        # attempt5 safely restored the v16 database but exposed a missing
        # `PHPWG_INSTALLED` declaration in the newly generated Piwigo DB
        # configuration. Preserve its volume for diagnosis. attempt6 starts
        # from the same verified DB-only input after that config fix, again on
        # exact disjoint bridges instead of Docker's exhausted default pool.
        'attempt6' {
            return @{
                name = 'attempt6'
                output_root = '.codex-work\v4-synthetic-migration-attempt6'
                project_name = 'class_archive_v4_synthetic_migration_attempt6'
                http_port = '8990'
                compat_port = '8991'
                app_network = 'class_archive_v4_synthetic_migration_attempt6_app'
                app_subnet = '10.255.1.0/24'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt6_gateway'
                gateway_subnet = '10.248.0.0/16'
                bff_gateway_ip = '10.248.0.10'
            }
        }
        # attempt6 proved the repaired Core-installed declaration but had
        # already reached a non-empty V16 state before the migration-specific
        # baseline verifier was added. Preserve it as evidence; attempt7 is a
        # new empty target for the complete, repeatable runner proof.
        'attempt7' {
            return @{
                name = 'attempt7'
                output_root = '.codex-work\v4-synthetic-migration-attempt7'
                project_name = 'class_archive_v4_synthetic_migration_attempt7'
                http_port = '9090'
                compat_port = '9091'
                app_network = 'class_archive_v4_synthetic_migration_attempt7_app'
                app_subnet = '10.255.2.0/24'
                gateway_network = 'class_archive_v4_synthetic_migration_attempt7_gateway'
                gateway_subnet = '10.247.0.0/16'
                bff_gateway_ip = '10.247.0.10'
            }
        }
        default { Stop-V4SyntheticMigration 'sandbox_attempt_invalid' }
    }
}

$sandboxSpec = Get-SandboxSpec $Attempt
$canonicalInputRoot = Join-Path $projectRoot '.codex-work\v4-synthetic-migration\input'
$sandboxRoot = Join-Path $projectRoot ([string]$sandboxSpec.output_root)
$configRoot = Join-Path $sandboxRoot 'config'
$inputRoot = $canonicalInputRoot
$reportRoot = Join-Path $sandboxRoot 'reports'
$envPath = Join-Path $configRoot '.env.piwigo'
$composePath = 'infra/docker-compose.yml'
$overridePath = 'infra/v4-synthetic-migration/docker-compose.override.yml'
$projectName = [string]$sandboxSpec.project_name
$httpPort = [string]$sandboxSpec.http_port
$compatPort = [string]$sandboxSpec.compat_port
$appNetwork = [string]$sandboxSpec.app_network
$appSubnet = [string]$sandboxSpec.app_subnet
$gatewayNetwork = [string]$sandboxSpec.gateway_network
$gatewaySubnet = [string]$sandboxSpec.gateway_subnet
$bffGatewayIp = [string]$sandboxSpec.bff_gateway_ip
$wsl = "$env:SystemRoot\System32\wsl.exe"
$script:stage = 'initialization'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-V4SyntheticMigration([string]$Code) {
    throw [InvalidOperationException]::new('V4_SYNTHETIC_MIGRATION_STOP:' + $Code)
}

function Write-V4SyntheticMigration([string]$State, [string]$Stage, [string]$Extra = '') {
    $suffix = if ([string]::IsNullOrWhiteSpace($Extra)) { '' } else { ' ' + $Extra }
    Write-Output ("V4_SYNTHETIC_MIGRATION={0} stage={1}{2}" -f $State, $Stage, $suffix)
}

function Set-V4Utf8ConsoleEncoding {
    try {
        $utf8 = [Text.UTF8Encoding]::new($false)
        [Console]::OutputEncoding = $utf8
        $script:OutputEncoding = $utf8
        if ([Console]::OutputEncoding.CodePage -ne 65001) { Stop-V4SyntheticMigration 'utf8_console_encoding_unavailable' }
    }
    catch {
        Stop-V4SyntheticMigration 'utf8_console_encoding_unavailable'
    }
}

Set-V4Utf8ConsoleEncoding

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4SyntheticMigration 'path_outside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-NoReparsePoints([string]$Path, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    if ($MustExist -and -not (Test-Path -LiteralPath $full)) { Stop-V4SyntheticMigration 'required_path_missing' }
    $current = if (Test-Path -LiteralPath $full) { Get-Item -LiteralPath $full -Force } else { Get-Item -LiteralPath (Split-Path -Parent $full) -Force }
    while ($null -ne $current) {
        if (($current.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
            Stop-V4SyntheticMigration 'reparse_point_forbidden'
        }
        # FileInfo has Directory rather than Parent; use the concrete type so
        # StrictMode keeps the reparse-point guard valid for the ignored env
        # file as well as the sandbox directories.
        $current = if ($current -is [IO.DirectoryInfo]) { $current.Parent } else { $current.Directory }
    }
}

function Assert-SandboxPath([string]$Path, [bool]$MustExist = $true) {
    $full = [IO.Path]::GetFullPath($Path)
    $allowed = $false
    foreach ($allowedRoot in @($sandboxRoot, $inputRoot)) {
        $rootPrefix = [IO.Path]::GetFullPath($allowedRoot).TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
        if (($full + [IO.Path]::DirectorySeparatorChar).StartsWith($rootPrefix, [StringComparison]::OrdinalIgnoreCase)) {
            $allowed = $true
            break
        }
    }
    if (-not $allowed) {
        Stop-V4SyntheticMigration 'sandbox_path_invalid'
    }
    if ($full -match '(^|[\\/])(?:owner|private|real|nas)(?:[\\/]|$)' -or $full -match '^[Mm]:') {
        Stop-V4SyntheticMigration 'private_or_source_path_forbidden'
    }
    Assert-NoReparsePoints -Path $full -MustExist:$MustExist
    return $full
}

function Assert-IgnoredUntracked([string]$Path, [string]$Label, [bool]$Directory) {
    $full = Assert-SandboxPath -Path $Path
    $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
    if (($Directory -and -not $item.PSIsContainer) -or (-not $Directory -and $item.PSIsContainer)) {
        Stop-V4SyntheticMigration ($Label + '_type_invalid')
    }
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V4SyntheticMigration ($Label + '_untrusted') }
    $relative = Get-ProjectRelativePath $full
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration ($Label + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-V4SyntheticMigration ($Label + '_tracked') }
    return $full
}

function New-V4Secret([int]$Bytes = 36) {
    $buffer = New-Object byte[] $Bytes
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($buffer) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Get-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $result = @(& $wsl -d Ubuntu --exec wslpath -a $full 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1) { Stop-V4SyntheticMigration 'wsl_path_conversion_failed' }
    $path = ([string]$result[0]).Trim()
    if ($path -notmatch '^/mnt/[a-z]/' -or $path.Contains('..') -or $path.Contains('//')) {
        Stop-V4SyntheticMigration 'wsl_path_invalid'
    }
    return $path
}

function Get-SandboxVolumeName([string]$LogicalName) {
    if ($LogicalName -notmatch '^[a-z0-9_]+$') { Stop-V4SyntheticMigration 'sandbox_volume_logical_name_invalid' }
    return $projectName + '_' + $LogicalName
}

function Get-ExpectedEnvValues([string]$SnapshotWslPath) {
    return @{
        COMPOSE_PROJECT_NAME = $projectName
        CLASS_ARCHIVE_HTTP_PORT = $httpPort
        CLASS_ARCHIVE_COMPAT_HTTP_PORT = $compatPort
        CLASS_ARCHIVE_CORE_PUBLIC_PORT = $httpPort
        CLASS_ARCHIVE_BASE_URL = ('http://127.0.0.1:' + $httpPort)
        CLASS_ARCHIVE_TIMEZONE = 'Asia/Shanghai'
        CLASS_ARCHIVE_V4_SANDBOX_APP_NETWORK = $appNetwork
        CLASS_ARCHIVE_V4_SANDBOX_APP_SUBNET = $appSubnet
        CLASS_ARCHIVE_GATEWAY_NETWORK = $gatewayNetwork
        CLASS_ARCHIVE_GATEWAY_SUBNET = $gatewaySubnet
        CLASS_ARCHIVE_BFF_GATEWAY_IP = $bffGatewayIp
        V4_SYNTHETIC_SNAPSHOT_PATH = $SnapshotWslPath
        PIWIGO_UID = '1000'
        PIWIGO_GID = '1000'
        PIWIGO_DATA_VOLUME = Get-SandboxVolumeName 'piwigo_data'
        PIWIGO_UPLOADS_VOLUME = Get-SandboxVolumeName 'piwigo_uploads'
        PIWIGO_GALLERIES_VOLUME = Get-SandboxVolumeName 'piwigo_galleries'
        PIWIGO_DERIVATIVES_VOLUME = Get-SandboxVolumeName 'piwigo_derivatives'
        PIWIGO_DB_VOLUME = Get-SandboxVolumeName 'piwigo_db'
        PIWIGO_SCRIPTS_VOLUME = Get-SandboxVolumeName 'piwigo_scripts'
        PIWIGO_BACKUPS_VOLUME = Get-SandboxVolumeName 'backups'
        V17_SYNTHETIC_RECOVERY_DB_VOLUME = Get-SandboxVolumeName 'v17_recovery_db'
        PIWIGO_IMAGE = 'piwigo/piwigo:16.4.0a@sha256:0ec6f159a3f972338b64e299d56ac37c442dd26cbeec39320d76ea826b5e0b84'
        MARIADB_IMAGE = 'mariadb:11.8.8@sha256:d9f7eb2637296652f24b484afd5d246f759f49f5babcadc6a9e344c9acb75fbf'
        DB_NAME = 'piwigo'
        DB_USER = 'piwigo'
        PIWIGO_ADMIN_USERNAME = 'synthetic-v4-sandbox-admin'
        PIWIGO_ADMIN_EMAIL = 'synthetic-v4-sandbox@class-archive.local'
        SMTP_HOST = ''
        SMTP_PORT = ''
        SMTP_USERNAME = ''
        SMTP_PASSWORD = ''
        SMTP_ENCRYPTION = ''
    }
}

function Get-SnapshotDirectory {
    $root = Assert-IgnoredUntracked -Path $inputRoot -Label 'sandbox_input' -Directory:$true
    $entries = @(Get-ChildItem -LiteralPath $root -Force -ErrorAction Stop)
    $children = @($entries | Where-Object {
        $_.PSIsContainer -and $_.Name -match '^pre-migration-db-v16-to-v17-[0-9]{8}T[0-9]{6}Z$'
    })
    # DirectoryInfo equality is reference-based in Windows PowerShell. A second
    # enumeration would otherwise reject the same valid input bundle.
    $childPaths = @($children | ForEach-Object { $_.FullName })
    $unexpected = @($entries | Where-Object { $_.FullName -notin $childPaths })
    if ($children.Count -ne 1 -or $unexpected.Count -ne 0) { Stop-V4SyntheticMigration 'snapshot_input_not_exactly_one_bundle' }
    return Assert-IgnoredUntracked -Path $children[0].FullName -Label 'snapshot_bundle' -Directory:$true
}

function Assert-SourceDbOnlySnapshot([string]$SnapshotPath) {
    $snapshot = Assert-IgnoredUntracked -Path $SnapshotPath -Label 'snapshot_bundle' -Directory:$true
    $allowed = @('COMPLETE', 'MANIFEST.json', 'SHA256SUMS', 'database.sql.gz')
    $entries = @(Get-ChildItem -LiteralPath $snapshot -Force -ErrorAction Stop)
    if ($entries.Count -ne $allowed.Count -or @($entries | Where-Object { $_.Name -notin $allowed }).Count -ne 0) {
        Stop-V4SyntheticMigration 'snapshot_file_set_invalid'
    }
    foreach ($name in $allowed) {
        $path = Join-Path $snapshot $name
        $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { Stop-V4SyntheticMigration 'snapshot_file_untrusted' }
    }
    $manifest = Get-Content -LiteralPath (Join-Path $snapshot 'MANIFEST.json') -Raw | ConvertFrom-Json -ErrorAction Stop
    if (
        $manifest.format -ne 1 -or $manifest.scope -ne 'DB_ONLY_PRE_MIGRATION_ROLLBACK' -or
        $manifest.schema_current -ne 16 -or $manifest.schema_from -ne 16 -or $manifest.schema_to -ne 17 -or
        $manifest.lock_strategy -ne 'MARIADB_DUMP_LOCK_ALL_TABLES' -or $manifest.media -ne 'NOT_INCLUDED' -or
        $manifest.dump_file -ne 'database.sql.gz' -or
        [string]$manifest.dump_sha256 -notmatch '^[a-f0-9]{64}$' -or
        [string]$manifest.snapshot_script_sha256 -notmatch '^[a-f0-9]{64}$'
    ) { Stop-V4SyntheticMigration 'snapshot_manifest_invalid' }
    $sourceScript = Join-Path $projectRoot 'infra\scripts\create-pre-migration-db-snapshot.sh'
    $sourceScriptHash = (Get-FileHash -LiteralPath $sourceScript -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not [string]::Equals($sourceScriptHash, [string]$manifest.snapshot_script_sha256, [StringComparison]::Ordinal)) {
        Stop-V4SyntheticMigration 'snapshot_not_created_by_existing_mechanism'
    }
    $dumpHash = (Get-FileHash -LiteralPath (Join-Path $snapshot 'database.sql.gz') -Algorithm SHA256).Hash.ToLowerInvariant()
    if (-not [string]::Equals($dumpHash, [string]$manifest.dump_sha256, [StringComparison]::Ordinal)) {
        Stop-V4SyntheticMigration 'snapshot_dump_hash_invalid'
    }
    $sumLines = @(Get-Content -LiteralPath (Join-Path $snapshot 'SHA256SUMS') | Where-Object { $_ -ne '' })
    if ($sumLines.Count -ne 3 -or @($sumLines | Where-Object { $_ -notmatch '^[a-f0-9]{64}  (COMPLETE|MANIFEST\.json|database\.sql\.gz)$' }).Count -ne 0) {
        Stop-V4SyntheticMigration 'snapshot_checksum_manifest_invalid'
    }
    foreach ($line in $sumLines) {
        $parts = $line -split '  ', 2
        $actual = (Get-FileHash -LiteralPath (Join-Path $snapshot $parts[1]) -Algorithm SHA256).Hash.ToLowerInvariant()
        if (-not [string]::Equals($actual, $parts[0], [StringComparison]::Ordinal)) { Stop-V4SyntheticMigration 'snapshot_checksum_failed' }
    }
    $complete = Get-Content -LiteralPath (Join-Path $snapshot 'COMPLETE') -Raw
    if ($complete -notmatch '^completed_at=[0-9]{8}T[0-9]{6}Z\r?\n$') { Stop-V4SyntheticMigration 'snapshot_complete_invalid' }
    return @{ path = $snapshot; name = (Split-Path -Leaf $snapshot); dump_sha256 = $dumpHash; created_at = [string]$manifest.created_at }
}

function Initialize-SandboxEnv([hashtable]$Snapshot) {
    foreach ($directory in @($sandboxRoot, $configRoot, $inputRoot, $reportRoot)) {
        if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory -Force | Out-Null }
        Assert-SandboxPath -Path $directory | Out-Null
        $relative = Get-ProjectRelativePath $directory
        & git -C $projectRoot check-ignore --quiet --no-index -- $relative
        if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'sandbox_output_not_ignored' }
    }
    if (Test-Path -LiteralPath $envPath) { Stop-V4SyntheticMigration 'sandbox_env_already_exists' }
    $snapshotWsl = Get-WslPath $Snapshot.path
    $expected = Get-ExpectedEnvValues $snapshotWsl
    $lines = [System.Collections.Generic.List[string]]::new()
    foreach ($key in @(
        'COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_HTTP_PORT','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_CORE_PUBLIC_PORT',
        'CLASS_ARCHIVE_BASE_URL','CLASS_ARCHIVE_TIMEZONE','CLASS_ARCHIVE_V4_SANDBOX_APP_NETWORK','CLASS_ARCHIVE_V4_SANDBOX_APP_SUBNET','CLASS_ARCHIVE_GATEWAY_NETWORK','CLASS_ARCHIVE_GATEWAY_SUBNET','CLASS_ARCHIVE_BFF_GATEWAY_IP','V4_SYNTHETIC_SNAPSHOT_PATH',
        'PIWIGO_UID','PIWIGO_GID','PIWIGO_DATA_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME',
        'PIWIGO_DERIVATIVES_VOLUME','PIWIGO_DB_VOLUME','PIWIGO_SCRIPTS_VOLUME','PIWIGO_BACKUPS_VOLUME','V17_SYNTHETIC_RECOVERY_DB_VOLUME',
        'PIWIGO_IMAGE','MARIADB_IMAGE','DB_NAME','DB_USER'
    )) { [void]$lines.Add($key + '=' + [string]$expected[$key]) }
    [void]$lines.Add('DB_PASSWORD=' + (New-V4Secret))
    [void]$lines.Add('DB_ROOT_PASSWORD=' + (New-V4Secret))
    [void]$lines.Add('CLASS_ARCHIVE_CLAIM_CODE_PEPPER=' + (New-V4Secret 48))
    [void]$lines.Add('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=' + (New-V4Secret 48))
    foreach ($key in @('PIWIGO_ADMIN_USERNAME','PIWIGO_ADMIN_EMAIL','SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_ENCRYPTION')) {
        [void]$lines.Add($key + '=' + [string]$expected[$key])
    }
    [IO.File]::WriteAllText($envPath, (($lines -join "`n") + "`n"), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    Write-V4SyntheticMigration 'READY' 'initialize' 'env=IGNORED_OWNER_ONLY snapshot=DB_ONLY'
}

function Read-StrictSandboxEnv([hashtable]$Snapshot) {
    Assert-IgnoredUntracked -Path $envPath -Label 'sandbox_env' -Directory:$false | Out-Null
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    $text = [IO.File]::ReadAllText($envPath, [Text.UTF8Encoding]::new($false, $true))
    if ($text.Contains("`0")) { Stop-V4SyntheticMigration 'sandbox_env_encoding_invalid' }
    $allowed = @(
        'COMPOSE_PROJECT_NAME','CLASS_ARCHIVE_HTTP_PORT','CLASS_ARCHIVE_COMPAT_HTTP_PORT','CLASS_ARCHIVE_CORE_PUBLIC_PORT',
        'CLASS_ARCHIVE_BASE_URL','CLASS_ARCHIVE_TIMEZONE','CLASS_ARCHIVE_V4_SANDBOX_APP_NETWORK','CLASS_ARCHIVE_V4_SANDBOX_APP_SUBNET','CLASS_ARCHIVE_GATEWAY_NETWORK','CLASS_ARCHIVE_GATEWAY_SUBNET','CLASS_ARCHIVE_BFF_GATEWAY_IP','V4_SYNTHETIC_SNAPSHOT_PATH',
        'PIWIGO_UID','PIWIGO_GID','PIWIGO_DATA_VOLUME','PIWIGO_UPLOADS_VOLUME','PIWIGO_GALLERIES_VOLUME',
        'PIWIGO_DERIVATIVES_VOLUME','PIWIGO_DB_VOLUME','PIWIGO_SCRIPTS_VOLUME','PIWIGO_BACKUPS_VOLUME','V17_SYNTHETIC_RECOVERY_DB_VOLUME',
        'PIWIGO_IMAGE','MARIADB_IMAGE','DB_NAME','DB_USER','DB_PASSWORD','DB_ROOT_PASSWORD',
        'CLASS_ARCHIVE_CLAIM_CODE_PEPPER','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET',
        'PIWIGO_ADMIN_USERNAME','PIWIGO_ADMIN_EMAIL','SMTP_HOST','SMTP_PORT','SMTP_USERNAME','SMTP_PASSWORD','SMTP_ENCRYPTION'
    )
    $values = @{}
    foreach ($line in ($text -split "`r?`n")) {
        if ($line -eq '') { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-V4SyntheticMigration 'sandbox_env_line_invalid' }
        $key = [string]$Matches[1]; $value = [string]$Matches[2]
        if ($key -notin $allowed -or $values.ContainsKey($key) -or $key -match '(?:SOURCE|NAS|OWNER|PRIVATE|REAL|MEDIA)') {
            Stop-V4SyntheticMigration 'sandbox_env_key_invalid'
        }
        if ($value.Contains("`0") -or $value.Contains("`r") -or $value.Contains("`n")) { Stop-V4SyntheticMigration 'sandbox_env_value_invalid' }
        $values[$key] = $value
    }
    if ($values.Count -ne $allowed.Count) { Stop-V4SyntheticMigration 'sandbox_env_key_set_invalid' }
    $expected = Get-ExpectedEnvValues (Get-WslPath $Snapshot.path)
    foreach ($entry in $expected.GetEnumerator()) {
        if (-not $values.ContainsKey($entry.Key) -or -not [string]::Equals([string]$values[$entry.Key], [string]$entry.Value, [StringComparison]::Ordinal)) {
            Stop-V4SyntheticMigration ('sandbox_env_exact_value_invalid_' + $entry.Key.ToLowerInvariant())
        }
    }
    foreach ($secret in @('DB_PASSWORD','DB_ROOT_PASSWORD','CLASS_ARCHIVE_CLAIM_CODE_PEPPER','CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET')) {
        $value = [string]$values[$secret]
        if ($value.Length -lt 32 -or $value.Length -gt 190 -or $value -notmatch '^[A-Za-z0-9_-]+$') { Stop-V4SyntheticMigration 'sandbox_env_secret_invalid' }
    }
    return $values
}

function Get-ComposePrefix {
    $relativeEnv = Get-ProjectRelativePath $envPath
    # Use WSL's direct-exec form with a Linux cwd. Without `--exec`, WSL can
    # route a nested `sh -c` probe through an extra shell, which can consume
    # database variables before they reach the isolated Compose container.
    # The runner never passes an arbitrary cwd; this is the verified checkout.
    $wslProjectRoot = Get-WslPath $projectRoot
    return @('-d', 'Ubuntu', '--cd', $wslProjectRoot, '--exec', 'docker', 'compose', '--env-file', $relativeEnv,
        '-f', $composePath, '-f', $overridePath, '-p', $projectName)
}

function Invoke-SandboxCompose([string[]]$Arguments, [switch]$Capture) {
    $prefix = Get-ComposePrefix
    if ($Capture) {
        $previous = $ErrorActionPreference
        try { $ErrorActionPreference = 'Continue'; $lines = @(& $wsl @($prefix + $Arguments) 2>&1); $exit = $LASTEXITCODE }
        finally { $ErrorActionPreference = $previous }
        if ($exit -ne 0) { Stop-V4SyntheticMigration 'sandbox_compose_command_failed' }
        return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
    }
    & $wsl @($prefix + $Arguments)
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'sandbox_compose_command_failed' }
    return @()
}

function Get-PropertyValue([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Assert-OptionalBrowserBffTopology([object]$Services) {
    # `immich-web-compat` is a service name only: this sandbox starts no
    # Immich Server, ML, PostgreSQL, Valkey, bridge or external-library
    # service.  Its name deliberately matches nginx's fixed dynamic upstream
    # so the browser remains behind Piwigo's loopback-only 8081 front proxy.
    foreach ($forbidden in @('immich-server','immich-machine-learning','database','redis','immich-gateway','immich-gateway-secret-stager')) {
        if ($null -ne (Get-PropertyValue $Services $forbidden)) { Stop-V4SyntheticMigration ('sandbox_browser_forbidden_service_' + ($forbidden -replace '[^a-z0-9]+','_')) }
    }
    $bff = Get-PropertyValue $Services 'immich-web-compat'
    if ($null -eq $bff) { Stop-V4SyntheticMigration 'sandbox_browser_bff_missing' }
    $profiles = @((Get-PropertyValue $bff 'profiles'))
    $entrypoint = @((Get-PropertyValue $bff 'entrypoint'))
    $capDrop = @((Get-PropertyValue $bff 'cap_drop'))
    $securityOpt = @((Get-PropertyValue $bff 'security_opt'))
    if ($profiles.Count -ne 1 -or [string]$profiles[0] -ne 'v4-synthetic-browser') {
        Stop-V4SyntheticMigration 'sandbox_browser_profile_invalid'
    }
    if ([string](Get-PropertyValue $bff 'image') -ne 'ghcr.io/immich-app/immich-server:v3.1.0@sha256:079cc990b26a88d71f96027341c67329cb11829d4c341ce33b3718fe0f84cbfa' -or
        $entrypoint.Count -ne 2 -or [string]$entrypoint[0] -ne 'node' -or [string]$entrypoint[1] -ne '/compat/server.mjs' -or
        [string](Get-PropertyValue $bff 'user') -ne '65532:65532' -or (Get-PropertyValue $bff 'read_only') -ne $true -or
        $capDrop -notcontains 'ALL' -or $securityOpt -notcontains 'no-new-privileges:true') {
        Stop-V4SyntheticMigration 'sandbox_browser_bff_hardening_invalid'
    }
    $ports = Get-PropertyValue $bff 'ports'
    if ($null -ne $ports -and @($ports).Count -ne 0) { Stop-V4SyntheticMigration 'sandbox_browser_bff_host_port_present' }
    $expose = @((Get-PropertyValue $bff 'expose'))
    if ($expose.Count -ne 1 -or [string]$expose[0] -ne '3000') {
        Stop-V4SyntheticMigration 'sandbox_browser_bff_expose_invalid'
    }
    $environment = Get-PropertyValue $bff 'environment'
    $expectedEnvironment = @{
        CLASS_ARCHIVE_WEB_COMPAT_PORT = '3000'
        CLASS_ARCHIVE_WEB_COMPAT_PUBLIC_PORT = $compatPort
        CLASS_ARCHIVE_CORE_PUBLIC_PORT = $httpPort
        CLASS_ARCHIVE_WEB_ROOT = '/web'
        CLASS_ARCHIVE_PHOTO_UI_ROOT = '/photo-ui'
        CLASS_ARCHIVE_GATEWAY_ORIGIN = 'http://piwigo:8088'
    }
    foreach ($expected in $expectedEnvironment.GetEnumerator()) {
        if ([string](Get-PropertyValue $environment $expected.Key) -ne [string]$expected.Value) {
            Stop-V4SyntheticMigration ('sandbox_browser_bff_environment_invalid_' + $expected.Key.ToLowerInvariant())
        }
    }
    $expectedMounts = @{
        '/compat' = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\web-compat')
        '/photo-ui' = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\photo-ui')
        '/web' = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\source\official-v3.1.0\web\build')
        '/data' = Get-WslPath (Join-Path $projectRoot 'infra\immich-spike\web-compat\empty-data')
    }
    $mounts = @((Get-PropertyValue $bff 'volumes'))
    if ($mounts.Count -ne $expectedMounts.Count) { Stop-V4SyntheticMigration 'sandbox_browser_bff_mount_count_invalid' }
    $seenTargets = @{}
    foreach ($mount in $mounts) {
        $target = [string](Get-PropertyValue $mount 'target')
        if ($seenTargets.ContainsKey($target) -or -not $expectedMounts.ContainsKey($target) -or [string](Get-PropertyValue $mount 'type') -ne 'bind' -or
            [string](Get-PropertyValue $mount 'source') -ne [string]$expectedMounts[$target] -or
            (Get-PropertyValue $mount 'read_only') -ne $true -or
            (Get-PropertyValue (Get-PropertyValue $mount 'bind') 'create_host_path') -ne $false) {
            Stop-V4SyntheticMigration 'sandbox_browser_bff_mount_invalid'
        }
        $seenTargets[$target] = $true
    }
    if ($seenTargets.Count -ne $expectedMounts.Count) { Stop-V4SyntheticMigration 'sandbox_browser_bff_mount_invalid' }
    $networks = Get-PropertyValue $bff 'networks'
    if ($null -eq $networks -or @($networks.PSObject.Properties).Count -ne 1 -or
        [string](Get-PropertyValue (Get-PropertyValue $networks 'immich_gateway') 'ipv4_address') -ne $bffGatewayIp) {
        Stop-V4SyntheticMigration 'sandbox_browser_bff_network_invalid'
    }
    $tmpfs = @((Get-PropertyValue $bff 'tmpfs'))
    if ($tmpfs.Count -ne 1 -or [string]$tmpfs[0] -ne '/tmp:mode=1777,size=8m') { Stop-V4SyntheticMigration 'sandbox_browser_bff_tmpfs_invalid' }
}

function Assert-ComposeTopology([hashtable]$Snapshot, [hashtable]$Values) {
    # Compose hides profiled services from an unprofiled `config` result. Audit
    # both isolated profiles before any sandbox container is created so the
    # restore helper and the optional BFF are both subject to the same topology
    # boundary checks.
    $jsonLines = Invoke-SandboxCompose -Arguments @(
        '--profile','v4-synthetic-migration',
        '--profile','v4-synthetic-browser',
        'config','--format','json'
    ) -Capture
    try { $config = ([string]::Join("`n", $jsonLines) | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-V4SyntheticMigration 'sandbox_compose_config_invalid' }
    if ([string](Get-PropertyValue $config 'name') -ne $projectName) { Stop-V4SyntheticMigration 'sandbox_project_invalid' }
    $services = Get-PropertyValue $config 'services'
    foreach ($required in @('db','piwigo','v4-synthetic-db-restore')) {
        if ($null -eq (Get-PropertyValue $services $required)) { Stop-V4SyntheticMigration ('sandbox_service_missing_' + $required) }
    }
    $piwigo = Get-PropertyValue $services 'piwigo'
    $ports = @((Get-PropertyValue $piwigo 'ports'))
    if ($ports.Count -ne 2) { Stop-V4SyntheticMigration 'sandbox_port_count_invalid' }
    $actualPorts = @{}
    foreach ($port in $ports) {
        if ([string](Get-PropertyValue $port 'host_ip') -ne '127.0.0.1') { Stop-V4SyntheticMigration 'sandbox_non_loopback_binding' }
        $actualPorts[[string](Get-PropertyValue $port 'published')] = [string](Get-PropertyValue $port 'target')
    }
    if ($actualPorts[$httpPort] -ne '80' -or $actualPorts[$compatPort] -ne '8081' -or $actualPorts.Count -ne 2) {
        Stop-V4SyntheticMigration 'sandbox_port_binding_invalid'
    }
    foreach ($property in $services.PSObject.Properties) {
        if ($property.Name -eq 'piwigo') { continue }
        $servicePorts = Get-PropertyValue $property.Value 'ports'
        if ($null -ne $servicePorts -and @($servicePorts).Count -gt 0) { Stop-V4SyntheticMigration ('sandbox_unexpected_host_port_' + $property.Name) }
    }
    $networks = Get-PropertyValue $config 'networks'
    foreach ($pair in @(@('app',$appNetwork), @('immich_gateway',$gatewayNetwork))) {
        $network = Get-PropertyValue $networks $pair[0]
        if ($null -eq $network -or [string](Get-PropertyValue $network 'name') -ne $pair[1] -or (Get-PropertyValue $network 'internal') -ne $true) {
            Stop-V4SyntheticMigration 'sandbox_network_invalid'
        }
    }
    foreach ($pair in @(@('app',$appSubnet), @('immich_gateway',$gatewaySubnet))) {
        $networkConfig = Get-PropertyValue $networks $pair[0]
        $ipam = Get-PropertyValue $networkConfig 'ipam'
        $ipamConfig = @((Get-PropertyValue $ipam 'config'))
        if ($ipamConfig.Count -ne 1 -or [string](Get-PropertyValue $ipamConfig[0] 'subnet') -ne $pair[1]) {
            Stop-V4SyntheticMigration ('sandbox_subnet_invalid_' + $pair[0])
        }
    }
    $volumes = Get-PropertyValue $config 'volumes'
    # Docker Compose omits a declared volume from rendered config when no
    # selected sandbox service mounts it. `backups` belongs to the unused ops
    # profile in this DB-only lab, while the six mounted volumes below are the
    # complete state that this runner may create or inspect.
    foreach ($logical in @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts')) {
        $record = Get-PropertyValue $volumes $logical
        $name = [string](Get-PropertyValue $record 'name')
        if ($null -eq $record -or $name -ne (Get-SandboxVolumeName $logical) -or (Get-PropertyValue $record 'external') -eq $true) {
            Stop-V4SyntheticMigration ('sandbox_volume_invalid_' + $logical)
        }
    }
    # The MariaDB service sees only its named data volume plus this checked-in,
    # read-only probe. It must never receive a snapshot, media, private path,
    # or a writable code bind merely to answer a schema/table-count question.
    $db = Get-PropertyValue $services 'db'
    $dbMounts = @((Get-PropertyValue $db 'volumes'))
    $expectedDbProbeSource = Get-WslPath (Join-Path $projectRoot 'infra\scripts\v4-synthetic-db-probe.sh')
    $expectedDbProbeTarget = '/workspace/infra/scripts/v4-synthetic-db-probe.sh'
    $dbNamed = @($dbMounts | Where-Object { [string](Get-PropertyValue $_ 'type') -eq 'volume' -and [string](Get-PropertyValue $_ 'target') -eq '/var/lib/mysql' })
    $dbProbe = @($dbMounts | Where-Object { [string](Get-PropertyValue $_ 'target') -eq $expectedDbProbeTarget })
    if ($dbMounts.Count -ne 2 -or $dbNamed.Count -ne 1 -or $dbProbe.Count -ne 1 -or
        # Rendered Compose mounts refer to the logical volume key; the
        # physical, project-scoped name is separately validated above.
        [string](Get-PropertyValue $dbNamed[0] 'source') -ne 'piwigo_db' -or
        (Get-PropertyValue $dbNamed[0] 'read_only') -eq $true -or
        [string](Get-PropertyValue $dbProbe[0] 'type') -ne 'bind' -or
        [string](Get-PropertyValue $dbProbe[0] 'source') -ne $expectedDbProbeSource -or
        (Get-PropertyValue $dbProbe[0] 'read_only') -ne $true -or
        (Get-PropertyValue (Get-PropertyValue $dbProbe[0] 'bind') 'create_host_path') -ne $false) {
        Stop-V4SyntheticMigration 'sandbox_db_probe_mount_invalid'
    }
    $restore = Get-PropertyValue $services 'v4-synthetic-db-restore'
    $mounts = @((Get-PropertyValue $restore 'volumes'))
    $snapshotMount = @($mounts | Where-Object { [string](Get-PropertyValue $_ 'target') -eq '/snapshot' })
    $expectedWsl = Get-WslPath $Snapshot.path
    if ($snapshotMount.Count -ne 1 -or [string](Get-PropertyValue $snapshotMount[0] 'type') -ne 'bind' -or
        [string](Get-PropertyValue $snapshotMount[0] 'source') -ne $expectedWsl -or
        (Get-PropertyValue $snapshotMount[0] 'read_only') -ne $true -or
        (Get-PropertyValue (Get-PropertyValue $snapshotMount[0] 'bind') 'create_host_path') -ne $false) {
        Stop-V4SyntheticMigration 'sandbox_snapshot_mount_invalid'
    }
    foreach ($mount in $mounts) {
        $source = [string](Get-PropertyValue $mount 'source')
        $target = [string](Get-PropertyValue $mount 'target')
        if ($source -match '(^|/)(?:owner|private|real|nas)(/|$)' -or $source -match '^/mnt/m/' -or $target -match '(?:upload|galleries|derivative)') {
            Stop-V4SyntheticMigration 'sandbox_forbidden_mount_present'
        }
    }
    Assert-OptionalBrowserBffTopology $services
    return $config
}

function Assert-FreshSandboxVolumes {
    $names = @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts','backups','v17_recovery_db' | ForEach-Object { Get-SandboxVolumeName $_ })
    $existing = @(& $wsl -d Ubuntu --exec docker volume ls --format '{{.Name}}' 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_volume_list_failed' }
    if (@($names | Where-Object { $_ -in $existing }).Count -ne 0) { Stop-V4SyntheticMigration 'sandbox_volumes_already_exist' }
    $containers = @(& $wsl -d Ubuntu --exec docker ps -a --filter ('label=com.docker.compose.project=' + $projectName) --format '{{.Names}}' 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_container_list_failed' }
    if (@($containers | Where-Object { $_ -ne '' }).Count -ne 0) { Stop-V4SyntheticMigration 'sandbox_containers_already_exist' }
    $networks = @(& $wsl -d Ubuntu --exec docker network ls --format '{{.Name}}' 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_network_list_failed' }
    if (@(@($appNetwork, $gatewayNetwork) | Where-Object { $_ -in $networks }).Count -ne 0) {
        Stop-V4SyntheticMigration 'sandbox_networks_already_exist'
    }
    $networkIds = @(& $wsl -d Ubuntu --exec docker network ls --quiet 2>$null)
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_network_list_failed' }
    foreach ($networkId in $networkIds) {
        if ([string]::IsNullOrWhiteSpace([string]$networkId)) { continue }
        $subnets = @(& $wsl -d Ubuntu --exec docker network inspect --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}' ([string]$networkId) 2>$null)
        if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_network_inspect_failed' }
        foreach ($requestedSubnet in @($appSubnet, $gatewaySubnet)) {
            if (@($subnets | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -eq $requestedSubnet }).Count -ne 0) {
                Stop-V4SyntheticMigration 'sandbox_requested_subnet_already_in_use'
            }
        }
    }
}

function Get-SandboxRestoreMode {
    $expectedVolumes = @('piwigo_data','piwigo_uploads','piwigo_galleries','piwigo_derivatives','piwigo_db','piwigo_scripts' | ForEach-Object { Get-SandboxVolumeName $_ })
    $v17RecoveryVolume = Get-SandboxVolumeName 'v17_recovery_db'
    $expectedNetworks = @($appNetwork, $gatewayNetwork)
    $expectedContainers = @(
        ($projectName + '-db-1'),
        ($projectName + '-piwigo-1')
    )
    $projectVolumePrefix = '^' + [regex]::Escape($projectName + '_')
    $volumes = @(& $wsl -d Ubuntu --exec docker volume ls --format '{{.Name}}' 2>$null | Where-Object { $_ -match $projectVolumePrefix })
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_volume_list_failed' }
    $networks = @(& $wsl -d Ubuntu --exec docker network ls --format '{{.Name}}' 2>$null | Where-Object { $_ -in $expectedNetworks })
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_network_list_failed' }
    $containers = @(& $wsl -d Ubuntu --exec docker ps -a --filter ('label=com.docker.compose.project=' + $projectName) --format '{{.Names}}' 2>$null | Where-Object { $_ -ne '' })
    if ($LASTEXITCODE -ne 0) { Stop-V4SyntheticMigration 'docker_container_list_failed' }

    # A format-9 recovery target is a second, independently written database.
    # Once it exists, this restore laboratory is no longer an empty v16 input
    # target and must be preserved rather than silently reused.
    if ($v17RecoveryVolume -in $volumes) { Stop-V4SyntheticMigration 'sandbox_v17_recovery_target_present' }

    if ($volumes.Count -eq 0 -and $networks.Count -eq 0 -and $containers.Count -eq 0) {
        return 'FRESH'
    }
    $volumeSet = @($volumes | Sort-Object)
    $networkSet = @($networks | Sort-Object)
    $containerSet = @($containers | Sort-Object)
    if (
        $volumeSet.Count -ne $expectedVolumes.Count -or $networkSet.Count -ne $expectedNetworks.Count -or $containerSet.Count -ne $expectedContainers.Count -or
        [string]::Join("`n", $volumeSet) -ne [string]::Join("`n", @($expectedVolumes | Sort-Object)) -or
        [string]::Join("`n", $networkSet) -ne [string]::Join("`n", @($expectedNetworks | Sort-Object)) -or
        [string]::Join("`n", $containerSet) -ne [string]::Join("`n", @($expectedContainers | Sort-Object))
    ) { Stop-V4SyntheticMigration 'sandbox_resume_state_invalid' }
    return 'RESUME_EMPTY'
}

function Wait-SandboxService([string]$Service, [int]$Seconds = 60) {
    for ($attempt = 0; $attempt -lt $Seconds; $attempt++) {
        $result = @(Invoke-SandboxCompose -Arguments @('ps', '--format', 'json', $Service) -Capture)
        if ($result.Count -eq 1) {
            try {
                $entry = $result[0] | ConvertFrom-Json -ErrorAction Stop
                if ([string](Get-PropertyValue $entry 'Health') -eq 'healthy') { return }
            } catch { }
        }
        Start-Sleep -Seconds 1
    }
    Stop-V4SyntheticMigration ('sandbox_service_not_healthy_' + $Service)
}

function Wait-SandboxServiceRunning([string]$Service, [int]$Seconds = 60) {
    # A restored v16/v17 Piwigo stays deliberately behind the fail-closed
    # maintenance marker until the migration finalizer succeeds. Its HTTP
    # health endpoint therefore returns 503 by design. Require a live
    # container here, not a published application health state; MariaDB and
    # the optional BFF retain the stricter healthy requirement above.
    for ($attempt = 0; $attempt -lt $Seconds; $attempt++) {
        $result = @(Invoke-SandboxCompose -Arguments @('ps', '--format', 'json', $Service) -Capture)
        if ($result.Count -eq 1) {
            try {
                $entry = $result[0] | ConvertFrom-Json -ErrorAction Stop
                if ([string](Get-PropertyValue $entry 'State') -eq 'running') { return }
            } catch { }
        }
        Start-Sleep -Seconds 1
    }
    Stop-V4SyntheticMigration ('sandbox_service_not_running_' + $Service)
}

function Get-SandboxSchemaVersion {
    $lines = @(Invoke-SandboxCompose -Arguments @(
        'exec','-T','db','sh','/workspace/infra/scripts/v4-synthetic-db-probe.sh','schema'
    ) -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(16|17)$') { Stop-V4SyntheticMigration 'sandbox_schema_probe_invalid' }
    return [int]$lines[0]
}

function Get-SandboxDatabaseTableCount {
    $lines = @(Invoke-SandboxCompose -Arguments @(
        'exec','-T','db','sh','/workspace/infra/scripts/v4-synthetic-db-probe.sh','table-count'
    ) -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^[0-9]+$') { Stop-V4SyntheticMigration 'sandbox_table_count_probe_invalid' }
    return [int]$lines[0]
}

function Invoke-SandboxRestore([hashtable]$Snapshot) {
    if (-not $ConfirmSyntheticRestore) { Stop-V4SyntheticMigration 'synthetic_restore_confirmation_required' }
    $script:stage = 'restore_target'
    $restoreMode = Get-SandboxRestoreMode
    if ($restoreMode -eq 'FRESH') { Assert-FreshSandboxVolumes }
    $script:stage = 'restore_bootstrap'
    Invoke-SandboxCompose -Arguments @('up','-d','db','piwigo') | Out-Null
    Wait-SandboxService -Service 'db'
    # A retry is permitted only if the first attempt reached the isolated
    # bootstrap state but imported no source table at all. This makes a runner
    # crash resumable without clearing or reusing any non-empty target.
    if ((Get-SandboxDatabaseTableCount) -ne 0) { Stop-V4SyntheticMigration 'sandbox_restore_target_not_empty' }
    # First launch only initializes the empty Piwigo code tree in the sandbox
    # data volume.  It is stopped before the DB-only restore writes its fresh
    # config and fail-closed maintenance marker.
    $script:stage = 'restore_import'
    Invoke-SandboxCompose -Arguments @('stop','piwigo') | Out-Null
    $lines = @(Invoke-SandboxCompose -Arguments @('--profile','v4-synthetic-migration','run','--rm','v4-synthetic-db-restore') -Capture)
    $record = @($lines | Where-Object { $_ -eq 'V4_SYNTHETIC_DB_RESTORE=PASS schema=16 scope=DB_ONLY media=NOT_MOUNTED target=ISOLATED maintenance=FAIL_CLOSED' })
    if ($record.Count -ne 1) { Stop-V4SyntheticMigration 'sandbox_db_restore_evidence_invalid' }
    $script:stage = 'restore_runtime'
    Invoke-SandboxCompose -Arguments @('up','-d','piwigo') | Out-Null
    Wait-SandboxServiceRunning -Service 'piwigo'
    if ((Get-SandboxSchemaVersion) -ne 16) { Stop-V4SyntheticMigration 'sandbox_restore_not_v16' }
    Write-V4SyntheticMigration 'PASS' 'restore' ('schema=16 source=DB_ONLY snapshot=' + $Snapshot.name + ' mode=' + $restoreMode + ' media=NOT_MOUNTED maintenance=FAIL_CLOSED')
}

function Invoke-SandboxMigration {
    if (-not $ConfirmSyntheticMigration) { Stop-V4SyntheticMigration 'synthetic_migration_confirmation_required' }
    $script:stage = 'migration_schema_source'
    if ((Get-SandboxSchemaVersion) -ne 16) { Stop-V4SyntheticMigration 'sandbox_migration_source_not_v16' }
    $script:stage = 'migration_prepare'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') | Out-Null
    $script:stage = 'migration_install'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php') | Out-Null
    $script:stage = 'migration_schema_target'
    if ((Get-SandboxSchemaVersion) -ne 17) { Stop-V4SyntheticMigration 'sandbox_migration_target_not_v17' }
    # Plugin bytes are volume-backed.  Recreate only the isolated Piwigo
    # container under its existing maintenance marker before verification;
    # never restart an Immich/ML service and never attach source media.
    $script:stage = 'migration_runtime'
    Invoke-SandboxCompose -Arguments @('up','-d','--force-recreate','--no-deps','piwigo') | Out-Null
    Wait-SandboxServiceRunning -Service 'piwigo'
    $script:stage = 'migration_verify_runtime'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') | Out-Null
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--verify-runtime') | Out-Null
    $script:stage = 'migration_projections'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json') | Out-Null
    $script:stage = 'migration_verifier'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION=1','piwigo','php','/workspace/infra/scripts/verify-v4-synthetic-post-migration.php') | Out-Null
    $script:stage = 'migration_finalize'
    Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance') | Out-Null
    Write-V4SyntheticMigration 'PASS' 'migrate' 'schema_from=16 schema_to=17 projections=REBUILT media=NOT_MOUNTED'
}

function Invoke-SandboxVerify {
    if ((Get-SandboxSchemaVersion) -ne 17) { Stop-V4SyntheticMigration 'sandbox_verify_not_v17' }
    $lines = @(Invoke-SandboxCompose -Arguments @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_SYNTHETIC_MIGRATION=1','piwigo','php','/workspace/infra/scripts/verify-v4-synthetic-post-migration.php') -Capture)
    if (@($lines | Where-Object { $_ -match '^V4_SYNTHETIC_MIGRATION_VERIFY=PASS schema=17 photos=72 pointers=8 snapshots=[0-9]+ items=[1-9][0-9]* media=NOT_MOUNTED media_guard=NOT_CLAIMED browser=NOT_CLAIMED$' }).Count -ne 1) {
        Stop-V4SyntheticMigration 'sandbox_verify_evidence_invalid'
    }
    Write-V4SyntheticMigration 'PASS' 'verify' 'schema=17 collection_snapshots=ACTIVE media=NOT_MOUNTED media_guard=NOT_CLAIMED'
}

function Invoke-SandboxBrowser {
    if (-not $ConfirmSyntheticBrowser) { Stop-V4SyntheticMigration 'synthetic_browser_confirmation_required' }
    if ((Get-SandboxSchemaVersion) -ne 17) { Stop-V4SyntheticMigration 'sandbox_browser_requires_schema_v17' }
    # The BFF is browser presentation evidence only.  Re-run the same DB-only
    # verifier first so it cannot mask a failed schema/projection migration as
    # a successful web process.  This still does not claim any media evidence:
    # the sandbox intentionally has no original/derivative volume mounts.
    Invoke-SandboxVerify
    Invoke-SandboxCompose -Arguments @('--profile','v4-synthetic-browser','up','-d','immich-web-compat') | Out-Null
    Wait-SandboxService -Service 'immich-web-compat'
    Write-V4SyntheticMigration 'PASS' 'browser' ('profile=v4-synthetic-browser ingress=127.0.0.1:' + $compatPort + '_via_piwigo_8081 bff_host_port=NONE gateway=http://piwigo:8088 media=NOT_MOUNTED')
}

try {
    $script:stage = 'snapshot'
    if ($Action -eq 'initialize') {
        # Initialization is the only action that may create sandbox output.
        # It still requires an existing, validated DB-only input bundle.
        if (-not (Test-Path -LiteralPath $inputRoot)) { Stop-V4SyntheticMigration 'snapshot_input_missing' }
        $snapshot = Assert-SourceDbOnlySnapshot (Get-SnapshotDirectory)
        Initialize-SandboxEnv $snapshot
        exit 0
    }

    $snapshot = Assert-SourceDbOnlySnapshot (Get-SnapshotDirectory)
    $script:stage = 'configuration'
    $values = Read-StrictSandboxEnv $snapshot
    $script:stage = 'topology'
    Assert-ComposeTopology $snapshot $values | Out-Null

    switch ($Action) {
        'validate' {
            Write-V4SyntheticMigration 'PASS' 'validate' ('project=' + $projectName + ' ports=127.0.0.1:' + $httpPort + '_' + $compatPort + ' source=DB_ONLY media=NOT_MOUNTED')
        }
        'restore' { Invoke-SandboxRestore $snapshot }
        'migrate' { Invoke-SandboxMigration }
        'verify' { Invoke-SandboxVerify }
        'browser' { Invoke-SandboxBrowser }
        'status' {
            $schema = $null
            try { $schema = Get-SandboxSchemaVersion }
            catch {
                # Status must stay non-sensitive: surface only our own fixed
                # stop code, never Compose/database output or environment data.
                $message = [string]$_.Exception.Message
                $schema = if ($message -match '^V4_SYNTHETIC_MIGRATION_STOP:([a-z0-9_]+)$') {
                    'UNAVAILABLE_' + [string]$Matches[1]
                } else {
                    'UNAVAILABLE'
                }
            }
            Write-V4SyntheticMigration 'STATUS' 'status' ('schema=' + $schema + ' project=' + $projectName)
        }
        default { Stop-V4SyntheticMigration 'action_invalid' }
    }
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message.StartsWith('V4_SYNTHETIC_MIGRATION_STOP:', [StringComparison]::Ordinal)) {
        $message.Substring('V4_SYNTHETIC_MIGRATION_STOP:'.Length)
    } elseif ($message -match '^[a-z0-9_]{1,96}$') {
        $message
    } else {
        'sandbox_runner_failed'
    }
    Write-V4SyntheticMigration 'FAIL' $script:stage ('code=' + $code)
    exit 2
}
