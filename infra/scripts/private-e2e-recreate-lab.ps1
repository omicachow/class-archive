[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('validate', 'config', 'prepare', 'drill', 'verify', 'cleanup')]
    [string]$Action = 'validate',

    [switch]$ConfirmOwnerReadOnlyClone,
    [switch]$ConfirmLabSigkill,
    [switch]$ConfirmLabCleanup,

    [string]$OwnerEnvPath
)

# Disposable proof for the real broker/AdminService/Audit/Core-revocation
# chain across an ungraceful Piwigo container recreation. Owner is a strictly
# read-only source. No service in this project publishes a host port.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composePath = Join-Path $projectRoot 'infra\private-e2e-recreate-lab\docker-compose.override.yml'
$boundedHelper = Join-Path $PSScriptRoot 'class-archive-bounded-native-process.ps1'
$labProject = 'class_archive_private_e2e_recreate_lab'
$labScope = 'private-e2e-recreate-lab'
$labPrefix = 'class_archive_private_e2e_recreate_lab'
$ownerProject = 'class_archive_private_full_v3_piwigo'
$ownerDbContainer = 'class_archive_private_full_v3_piwigo-db-1'
$ownerPiwigoContainer = 'class_archive_private_full_v3_piwigo-piwigo-1'
$runtimeRoot = Join-Path $projectRoot '.codex-work\private-e2e-recreate-lab'
$statePath = Join-Path $runtimeRoot 'state.json'
$wsl = Join-Path $env:SystemRoot 'System32\wsl.exe'
$leaseTtlSeconds = 300

if ([string]::IsNullOrWhiteSpace($OwnerEnvPath)) {
    $OwnerEnvPath = Join-Path $projectRoot 'infra\private-full\.env.piwigo.owner'
}

function Stop-RecreateLab([string]$Code) {
    if ($Code -notmatch '^[a-z0-9_]{3,112}$') { $Code = 'invalid_failure_code' }
    throw [InvalidOperationException]::new('PRIVATE_E2E_RECREATE_LAB_STOP:' + $Code)
}

function Get-RecreateLabProperty([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

foreach ($required in @($composePath, $boundedHelper, $wsl)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        Stop-RecreateLab 'required_file_missing'
    }
}
. $boundedHelper

try {
    $utf8 = [Text.UTF8Encoding]::new($false)
    [Console]::OutputEncoding = $utf8
    $script:OutputEncoding = $utf8
} catch { Stop-RecreateLab 'utf8_console_unavailable' }

function Get-WslPath([string]$Path) {
    try { $full = [IO.Path]::GetFullPath($Path) } catch { Stop-RecreateLab 'wsl_path_invalid' }
    if ($full -notmatch '^([A-Za-z]):\\(.+)$') { Stop-RecreateLab 'wsl_path_invalid' }
    $drive = $Matches[1].ToLowerInvariant()
    $parts = @($Matches[2] -split '\\')
    if ($parts.Count -lt 1 -or @($parts | Where-Object {
            [string]::IsNullOrWhiteSpace($_) -or $_ -in @('.', '..') -or $_ -match '[/\x00:]'
        }).Count -ne 0) {
        Stop-RecreateLab 'wsl_path_invalid'
    }
    return '/mnt/' + $drive + '/' + ($parts -join '/')
}

function Assert-OwnerEnvFile {
    $expected = [IO.Path]::GetFullPath((Join-Path $projectRoot 'infra\private-full\.env.piwigo.owner'))
    $actual = [IO.Path]::GetFullPath($OwnerEnvPath)
    if (-not [string]::Equals($actual, $expected, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-RecreateLab 'owner_env_path_not_exact'
    }
    $item = Get-Item -LiteralPath $actual -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-RecreateLab 'owner_env_path_untrusted'
    }
    & git -C $projectRoot check-ignore --quiet --no-index -- 'infra/private-full/.env.piwigo.owner'
    if ($LASTEXITCODE -ne 0) { Stop-RecreateLab 'owner_env_not_ignored' }
    $tracked = @(& git -C $projectRoot ls-files -- 'infra/private-full/.env.piwigo.owner' 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { Stop-RecreateLab 'owner_env_tracked' }
}

function Get-LabComposeArguments([string[]]$Tail) {
    return @(
        '-d', 'Ubuntu', '--exec', 'docker', 'compose',
        '--env-file', (Get-WslPath $OwnerEnvPath),
        '-f', (Get-WslPath $composePath),
        '-p', $labProject
    ) + $Tail
}

function Invoke-RecreateLabWsl(
    [string[]]$Arguments,
    [string]$Code,
    [ValidateRange(1, 900)][int]$TimeoutSeconds = 120,
    [switch]$Capture
) {
    try {
        # The shared host runner is capped at 900 seconds. Reserve a bounded
        # 15-second host-side grace window instead of passing 915 and failing
        # ValidateRange before the child starts.
        $wslTimeoutSeconds = [Math]::Min($TimeoutSeconds, 885)
        $hostTimeoutSeconds = [Math]::Min($wslTimeoutSeconds + 15, 900)
        $bounded = Add-ClassArchiveWslTimeout -Arguments $Arguments -TimeoutSeconds $wslTimeoutSeconds
        $result = Invoke-ClassArchiveBoundedNative -Executable $wsl -Arguments $bounded `
            -TimeoutSeconds $hostTimeoutSeconds -WorkingDirectory $projectRoot
    } catch { Stop-RecreateLab ($Code + '_start_failed') }
    if ($result.TimedOut) { Stop-RecreateLab ($Code + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-RecreateLab $Code }
    if ($Capture) {
        return @(([string]$result.Stdout -split "`r?`n") | Where-Object { $_ -ne '' })
    }
    return @()
}

function Invoke-LabCompose(
    [string[]]$Tail,
    [string]$Code,
    [ValidateRange(1, 900)][int]$TimeoutSeconds = 120,
    [switch]$Capture
) {
    return Invoke-RecreateLabWsl (Get-LabComposeArguments $Tail) $Code $TimeoutSeconds -Capture:$Capture
}

function Invoke-Docker(
    [string[]]$Tail,
    [string]$Code,
    [ValidateRange(1, 900)][int]$TimeoutSeconds = 120,
    [switch]$Capture
) {
    return Invoke-RecreateLabWsl (@('-d', 'Ubuntu', '--exec', 'docker') + $Tail) $Code $TimeoutSeconds -Capture:$Capture
}

function Invoke-Bash(
    [string]$Program,
    [string]$Code,
    [ValidateRange(1, 900)][int]$TimeoutSeconds = 120,
    [switch]$Capture
) {
    if ($Program.Contains("`0")) { Stop-RecreateLab 'bash_program_invalid' }
    return Invoke-RecreateLabWsl @('-d', 'Ubuntu', '--exec', 'bash', '-o', 'pipefail', '-eu', '-c', $Program) `
        $Code $TimeoutSeconds -Capture:$Capture
}

function Assert-ContainerBinding([string]$Container, [string]$Project, [string]$Service, [string]$Scope = '') {
    if ($Container -notmatch '^[a-z0-9][a-z0-9_.-]{2,112}$') { Stop-RecreateLab 'container_name_invalid' }
    $lines = @(Invoke-Docker @('inspect', '--format',
        '{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}|{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}|{{index .Config.Labels "com.classarchive.scope"}}|{{json .HostConfig.PortBindings}}',
        $Container) 'container_inspect_failed' 30 -Capture)
    if ($lines.Count -ne 1) { Stop-RecreateLab 'container_inspect_ambiguous' }
    $parts = @($lines[0] -split '\|', 6)
    if ($parts.Count -ne 6 -or $parts[0] -ne 'running' -or $parts[2] -ne $Project -or $parts[3] -ne $Service) {
        Stop-RecreateLab 'container_identity_mismatch'
    }
    if ($Scope -ne '' -and $parts[4] -ne $Scope) { Stop-RecreateLab 'container_scope_mismatch' }
    if ($Project -eq $labProject -and $parts[5] -notin @('{}', 'null')) {
        Stop-RecreateLab 'lab_port_binding_present'
    }
    if ($Service -in @('db', 'piwigo') -and $parts[1] -ne 'healthy') {
        Stop-RecreateLab 'container_not_healthy'
    }
}

function Assert-OwnerRuntime {
    Assert-ContainerBinding $ownerDbContainer $ownerProject 'db' 'private-real-full'
    Assert-ContainerBinding $ownerPiwigoContainer $ownerProject 'piwigo' 'private-real-full'
}

function Get-DumpDigest([string]$Container) {
    if ($Container -notin @($ownerDbContainer, ($labProject + '-db-1'))) {
        Stop-RecreateLab 'dump_container_not_allowlisted'
    }
    $program = @'
docker exec __CONTAINER__ sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\" ORDER BY TABLE_NAME")
  test -n "$prefix" && test "$(printf %s "$prefix" | wc -l)" = 0
  case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  exec mariadb-dump --quick --lock-all-tables --skip-comments --skip-dump-date --hex-blob \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}sessions" \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache" \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache_categories" \
    --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
' | sha256sum | cut -d ' ' -f 1
'@.Replace('__CONTAINER__', $Container)
    $lines = @(Invoke-Bash $program 'logical_dump_digest_failed' 900 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^[a-f0-9]{64}$') {
        Stop-RecreateLab 'logical_dump_digest_invalid'
    }
    return [string]$lines[0]
}

function Get-BusinessCountVector([string]$Container) {
    if ($Container -notin @($ownerDbContainer, ($labProject + '-db-1'))) {
        Stop-RecreateLab 'count_container_not_allowlisted'
    }
    $program = @'
docker exec __CONTAINER__ sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\" ORDER BY TABLE_NAME")
  test -n "$prefix" && test "$(printf %s "$prefix" | wc -l)" = 0
  case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT CONCAT_WS(\"|\",(SELECT COUNT(*) FROM \`${prefix}class_identity_identity\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_seat\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_account\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_principal\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_photo\`),(SELECT COUNT(*) FROM \`${prefix}class_identity_photo_source\`),(SELECT COUNT(*) FROM \`${prefix}images\`),(SELECT COUNT(*) FROM \`${prefix}categories\`),(SELECT COUNT(*) FROM \`${prefix}image_category\`))"
'
'@.Replace('__CONTAINER__', $Container)
    $lines = @(Invoke-Bash $program 'business_count_vector_failed' 120 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(?:[0-9]+\|){9}[0-9]+$') {
        Stop-RecreateLab 'business_count_vector_invalid'
    }
    return [string]$lines[0]
}

function Get-LabFixtureVector {
    $program = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\"")
  test -n "$prefix"; case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT CONCAT_WS(\"|\",i.id,i.state,i.lock_version,(SELECT COALESCE(SUM(p.auth_epoch),0) FROM \`${prefix}class_identity_principal\` p JOIN \`${prefix}class_identity_account\` a ON a.id=p.account_id AND a.current_marker=1 JOIN \`${prefix}class_identity_seat\` s ON s.id=a.seat_id WHERE s.identity_id=i.id AND p.principal_type=\"SEAT_ACCOUNT\"),(SELECT COUNT(*) FROM \`${prefix}user_auth_keys\` k WHERE k.user_id IN (SELECT p2.piwigo_user_id FROM \`${prefix}class_identity_principal\` p2 JOIN \`${prefix}class_identity_account\` a2 ON a2.id=p2.account_id AND a2.current_marker=1 JOIN \`${prefix}class_identity_seat\` s2 ON s2.id=a2.seat_id WHERE s2.identity_id=i.id) AND k.revoked_on IS NULL),(SELECT COALESCE(MAX(ae.id),0) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=i.id)) FROM \`${prefix}class_identity_identity\` i WHERE i.roster_code=\"FQA-C-99CA3B3B6AF1\""
'
'@
    $lines = @(Invoke-Bash $program 'lab_fixture_vector_failed' 60 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^[0-9]+\|(ACTIVE|FROZEN)\|[0-9]+\|[0-9]+\|[0-9]+\|[0-9]+$') {
        Stop-RecreateLab 'lab_fixture_vector_invalid'
    }
    return [string]$lines[0]
}

function Get-LabLeaseEvidence([string]$Run) {
    if ($Run -notmatch '^[a-f0-9]{24}$') { Stop-RecreateLab 'run_marker_invalid' }
    $program = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\"")
  test -n "$prefix"; case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT CONCAT_WS(\"|\",l.state,l.lease_revision,(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=l.resource_id AND ae.action=\"IDENTITY_UNFREEZE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=l.resource_id AND ae.action=\"IDENTITY_FREEZE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=l.resource_id AND ae.action=\"PRINCIPAL_SECURITY_CHANGE\" AND JSON_UNQUOTE(JSON_EXTRACT(ae.new_value,\"$.reason_code\"))=\"LOCAL_FQA_LEASE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=l.resource_id AND ae.action=\"PRINCIPAL_SECURITY_CHANGE\" AND JSON_UNQUOTE(JSON_EXTRACT(ae.new_value,\"$.reason_code\"))=\"LOCAL_FQA_LEASE_CLEANUP\")) FROM \`${prefix}class_identity_private_e2e_fixture_lease\` l WHERE l.test_run_id=\"__RUN__\" AND l.fixture_owner=\"v4-owner-fqa-broker\" ORDER BY l.acquired_at DESC,l.lease_id DESC LIMIT 1"
'
'@.Replace('__RUN__', $Run)
    $lines = @(Invoke-Bash $program 'lab_lease_evidence_failed' 60 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(ACTIVE|RELEASED|ABANDONED|CONFLICT)\|[0-9]+\|[0-9]+\|[0-9]+\|[0-9]+\|[0-9]+$') {
        Stop-RecreateLab 'lab_lease_evidence_invalid'
    }
    return [string]$lines[0]
}

function Get-LabLeaseLineageEvidence([string]$Run) {
    if ($Run -notmatch '^[a-f0-9]{24}$') { Stop-RecreateLab 'run_marker_invalid' }
    $program = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\"")
  test -n "$prefix"; case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT CONCAT_WS(\"|\",SUM(l.state=\"ABANDONED\"),SUM(l.state=\"RELEASED\"),SUM(l.recovered_from_lease_id IS NOT NULL AND p.state=\"ABANDONED\")) FROM \`${prefix}class_identity_private_e2e_fixture_lease\` l LEFT JOIN \`${prefix}class_identity_private_e2e_fixture_lease\` p ON p.lease_id=l.recovered_from_lease_id WHERE l.test_run_id=\"__RUN__\" AND l.fixture_owner=\"v4-owner-fqa-broker\""
'
'@.Replace('__RUN__', $Run)
    $lines = @(Invoke-Bash $program 'lab_lease_lineage_evidence_failed' 60 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^[0-9]+\|[0-9]+\|[0-9]+$') {
        Stop-RecreateLab 'lab_lease_lineage_evidence_invalid'
    }
    return [string]$lines[0]
}

function Get-LabAuditEvidence {
    $program = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\"")
  test -n "$prefix"; case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT CONCAT_WS(\"|\",(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=i.id AND ae.action=\"IDENTITY_UNFREEZE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=i.id AND ae.action=\"IDENTITY_FREEZE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=i.id AND ae.action=\"PRINCIPAL_SECURITY_CHANGE\" AND JSON_UNQUOTE(JSON_EXTRACT(ae.new_value,\"$.reason_code\"))=\"LOCAL_FQA_LEASE\"),(SELECT COUNT(*) FROM \`${prefix}class_identity_audit_event\` ae WHERE ae.target_identity_id=i.id AND ae.action=\"PRINCIPAL_SECURITY_CHANGE\" AND JSON_UNQUOTE(JSON_EXTRACT(ae.new_value,\"$.reason_code\"))=\"LOCAL_FQA_LEASE_CLEANUP\")) FROM \`${prefix}class_identity_identity\` i WHERE i.roster_code=\"FQA-C-99CA3B3B6AF1\""
'
'@
    $lines = @(Invoke-Bash $program 'lab_audit_evidence_failed' 60 -Capture)
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^(?:[0-9]+\|){3}[0-9]+$') {
        Stop-RecreateLab 'lab_audit_evidence_invalid'
    }
    return [string]$lines[0]
}

function Write-LabState([hashtable]$State) {
    if (-not (Test-Path -LiteralPath $runtimeRoot -PathType Container)) {
        [void](New-Item -ItemType Directory -Path $runtimeRoot -Force)
    }
    $rootItem = Get-Item -LiteralPath $runtimeRoot -Force
    if ($rootItem.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-RecreateLab 'runtime_root_untrusted' }
    & git -C $projectRoot check-ignore --quiet --no-index -- '.codex-work/private-e2e-recreate-lab/state.json'
    if ($LASTEXITCODE -ne 0) { Stop-RecreateLab 'runtime_state_not_ignored' }
    $json = $State | ConvertTo-Json -Depth 8
    [IO.File]::WriteAllText($statePath, $json + "`n", [Text.UTF8Encoding]::new($false))
}

function Read-LabState {
    if (-not (Test-Path -LiteralPath $statePath -PathType Leaf)) { Stop-RecreateLab 'runtime_state_missing' }
    $item = Get-Item -LiteralPath $statePath -Force
    if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-RecreateLab 'runtime_state_untrusted' }
    try { return Get-Content -LiteralPath $statePath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch {
        Stop-RecreateLab 'runtime_state_invalid'
    }
}

function Wait-LabHealthy([string]$Service, [ValidateRange(1, 180)][int]$Seconds = 90) {
    $container = $labProject + '-' + $Service + '-1'
    $deadline = [DateTime]::UtcNow.AddSeconds($Seconds)
    do {
        try {
            Assert-ContainerBinding $container $labProject $Service $labScope
            return
        } catch {
            Start-Sleep -Milliseconds 750
        }
    } while ([DateTime]::UtcNow -lt $deadline)
    Stop-RecreateLab ('lab_' + $Service + '_not_healthy')
}

function Assert-LabRuntimeObjects {
    Assert-ContainerBinding ($labProject + '-db-1') $labProject 'db' $labScope
    Assert-ContainerBinding ($labProject + '-piwigo-1') $labProject 'piwigo' $labScope
    $mounts = @(Invoke-Docker @('inspect', '--format', '{{range .Mounts}}{{println .Name "|" .Destination "|" .RW}}{{end}}', ($labProject + '-piwigo-1')) `
        'lab_mount_inspect_failed' 30 -Capture)
    $recovery = @($mounts | Where-Object { $_ -eq 'class_archive_private_e2e_recreate_lab_recovery | /var/lib/class-archive-private-e2e | true' })
    if ($recovery.Count -ne 1) { Stop-RecreateLab 'lab_recovery_mount_invalid' }
    foreach ($forbidden in @('class_archive_private_full_v3_piwigo_uploads', 'class_archive_private_full_v3_piwigo_galleries', 'class_archive_private_full_v3_piwigo_derivatives')) {
        if (@($mounts | Where-Object { $_ -match [regex]::Escape($forbidden) }).Count -ne 0) {
            Stop-RecreateLab 'owner_media_volume_leaked'
        }
    }
}

function Assert-CleanupScope {
    $containerLines = @(Invoke-Docker @('ps', '-a', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Names}}|{{.Label "com.docker.compose.project"}}') `
        'cleanup_container_enumeration_failed' 30 -Capture)
    foreach ($line in $containerLines) {
        $parts = @($line -split '\|', 2)
        if ($parts.Count -ne 2 -or -not $parts[0].StartsWith($labPrefix + '-', [StringComparison]::Ordinal) -or $parts[1] -ne $labProject) {
            Stop-RecreateLab 'cleanup_container_scope_mismatch'
        }
    }
    $prefixContainers = @(Invoke-Docker @('ps', '-a', '--filter', ('name=^/' + $labPrefix), '--format', '{{.Names}}|{{.Label "com.classarchive.scope"}}') `
        'cleanup_prefix_container_enumeration_failed' 30 -Capture)
    foreach ($line in $prefixContainers) {
        if ($line -notmatch ('^' + [regex]::Escape($labPrefix) + '-[^|]+\|' + [regex]::Escape($labScope) + '$')) {
            Stop-RecreateLab 'cleanup_prefix_container_unlabelled'
        }
    }
    $volumeLines = @(Invoke-Docker @('volume', 'ls', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Name}}') `
        'cleanup_volume_enumeration_failed' 30 -Capture)
    foreach ($name in $volumeLines) {
        if (-not $name.StartsWith($labPrefix + '_', [StringComparison]::Ordinal)) {
            Stop-RecreateLab 'cleanup_volume_scope_mismatch'
        }
    }
    $prefixVolumes = @(Invoke-Docker @('volume', 'ls', '--filter', ('name=' + $labPrefix), '--format', '{{.Name}}|{{.Labels}}') `
        'cleanup_prefix_volume_enumeration_failed' 30 -Capture)
    foreach ($line in $prefixVolumes) {
        if (-not $line.StartsWith($labPrefix + '_', [StringComparison]::Ordinal) -or $line -notmatch [regex]::Escape('com.classarchive.scope=private-e2e-recreate-lab')) {
            Stop-RecreateLab 'cleanup_prefix_volume_unlabelled'
        }
    }
    $networkLines = @(Invoke-Docker @('network', 'ls', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Name}}') `
        'cleanup_network_enumeration_failed' 30 -Capture)
    foreach ($name in $networkLines) {
        if ($name -ne ($labPrefix + '_network')) { Stop-RecreateLab 'cleanup_network_scope_mismatch' }
    }
    $prefixNetworks = @(Invoke-Docker @('network', 'ls', '--filter', ('name=' + $labPrefix), '--format', '{{.Name}}|{{.Labels}}') `
        'cleanup_prefix_network_enumeration_failed' 30 -Capture)
    foreach ($line in $prefixNetworks) {
        if (-not $line.StartsWith($labPrefix + '_', [StringComparison]::Ordinal) -or $line -notmatch [regex]::Escape('com.classarchive.scope=private-e2e-recreate-lab')) {
            Stop-RecreateLab 'cleanup_prefix_network_unlabelled'
        }
    }
}

function Invoke-Validate {
    Assert-OwnerEnvFile
    $config = @(Invoke-LabCompose @('config', '--format', 'json') 'lab_compose_config_failed' 60 -Capture)
    if ($config.Count -lt 1) { Stop-RecreateLab 'lab_compose_config_empty' }
    $joined = $config -join "`n"
    try { $model = $joined | ConvertFrom-Json -ErrorAction Stop } catch { Stop-RecreateLab 'lab_compose_config_json_invalid' }
    if ([string]$model.name -ne $labProject) { Stop-RecreateLab 'lab_compose_project_invalid' }
    foreach ($serviceName in @('db', 'piwigo')) {
        $service = Get-RecreateLabProperty $model.services $serviceName
        $labels = Get-RecreateLabProperty $service 'labels'
        if ($null -eq $service -or $null -ne (Get-RecreateLabProperty $service 'ports') -or
            [string](Get-RecreateLabProperty $labels 'com.classarchive.scope') -ne $labScope) {
            Stop-RecreateLab 'lab_compose_service_boundary_invalid'
        }
    }
    $labNetwork = Get-RecreateLabProperty $model.networks 'lab'
    if ((Get-RecreateLabProperty $labNetwork 'internal') -ne $true) { Stop-RecreateLab 'lab_network_not_internal' }
    foreach ($forbidden in @('class_archive_private_full_v3_piwigo_uploads', 'class_archive_private_full_v3_piwigo_galleries', 'class_archive_private_full_v3_piwigo_derivatives')) {
        if ($joined.Contains($forbidden)) { Stop-RecreateLab 'owner_media_volume_in_compose' }
    }
    Write-Output 'PRIVATE_E2E_RECREATE_LAB=PASS action=validate ports=NONE owner_media=ABSENT project=FIXED cleanup=LABEL_AND_PREFIX'
}

function Invoke-Prepare {
    if (-not $ConfirmOwnerReadOnlyClone) { Stop-RecreateLab 'owner_readonly_clone_confirmation_required' }
    Assert-OwnerEnvFile
    Assert-OwnerRuntime
    Assert-CleanupScope
    $existing = @(Invoke-Docker @('ps', '-a', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.ID}}') `
        'lab_existing_container_check_failed' 30 -Capture)
    $existingVolumes = @(Invoke-Docker @('volume', 'ls', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Name}}') `
        'lab_existing_volume_check_failed' 30 -Capture)
    if ($existing.Count -ne 0 -or $existingVolumes.Count -ne 0) { Stop-RecreateLab 'lab_not_empty' }

    $ownerDigestBefore = Get-DumpDigest $ownerDbContainer
    $ownerCountsBefore = Get-BusinessCountVector $ownerDbContainer

    Invoke-LabCompose @('up', '-d', 'db') 'lab_db_start_failed' 180 | Out-Null
    Wait-LabHealthy 'db' 120
    $emptyProgram = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  test "$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")" = 0
'
'@
    Invoke-Bash $emptyProgram 'lab_database_not_empty' 60 | Out-Null

    # The only database copy path: an Owner read lock and a direct SQL stream
    # into the independent lab database. No SQL file or row is reflected.
    $streamProgram = @'
docker exec class_archive_private_full_v3_piwigo-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\" ORDER BY TABLE_NAME")
  test -n "$prefix" && test "$(printf %s "$prefix" | wc -l)" = 0
  case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  exec mariadb-dump --quick --lock-all-tables --skip-comments --skip-dump-date --hex-blob \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}sessions" \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache" \
    --ignore-table-data="$MARIADB_DATABASE.${prefix}user_cache_categories" \
    --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
' | docker exec -i class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  exec mariadb --host=127.0.0.1 --user=root "$MARIADB_DATABASE"
'
'@
    Invoke-Bash $streamProgram 'lab_database_stream_clone_failed' 900 | Out-Null
    $labRawDigest = Get-DumpDigest ($labProject + '-db-1')
    if (-not [string]::Equals($ownerDigestBefore, $labRawDigest, [StringComparison]::Ordinal)) {
        Stop-RecreateLab 'lab_database_clone_digest_mismatch'
    }
    $labRawCounts = Get-BusinessCountVector ($labProject + '-db-1')
    if ($labRawCounts -ne $ownerCountsBefore) { Stop-RecreateLab 'lab_database_clone_count_mismatch' }

    Invoke-LabCompose @('--profile', 'seed', 'run', '--rm', '--no-deps', 'seed-piwigo') `
        'lab_piwigo_control_seed_failed' 900 | Out-Null
    Invoke-LabCompose @('up', '-d', 'piwigo') 'lab_piwigo_start_failed' 240 | Out-Null
    Wait-LabHealthy 'piwigo' 180
    Assert-LabRuntimeObjects
    Invoke-LabCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php') `
        'lab_current_plugin_install_failed' 300 | Out-Null
    Invoke-LabCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'php', '/workspace/infra/scripts/install-class-archive-plugins.php', '--finalize-maintenance') `
        'lab_current_plugin_finalize_failed' 300 | Out-Null

    $ownerDigestAfter = Get-DumpDigest $ownerDbContainer
    $ownerCountsAfter = Get-BusinessCountVector $ownerDbContainer
    if ($ownerDigestAfter -ne $ownerDigestBefore -or $ownerCountsAfter -ne $ownerCountsBefore) {
        Stop-RecreateLab 'owner_changed_during_lab_prepare'
    }
    $fixtureVector = Get-LabFixtureVector
    if (($fixtureVector -split '\|')[1] -ne 'FROZEN') { Stop-RecreateLab 'lab_fixture_not_frozen' }
    $piwigoIds = @(Invoke-Docker @('inspect', '--format', '{{.Id}}', ($labProject + '-piwigo-1')) 'lab_container_id_failed' 30 -Capture)
    if ($piwigoIds.Count -ne 1) { Stop-RecreateLab 'lab_container_id_ambiguous' }
    $piwigoId = $piwigoIds[0]
    Write-LabState @{
        version = 1
        project = $labProject
        scope = $labScope
        prepared_at = [DateTime]::UtcNow.ToString('o')
        owner_dump_sha256 = $ownerDigestBefore
        owner_count_vector = $ownerCountsBefore
        raw_clone_dump_sha256 = $labRawDigest
        raw_clone_count_vector = $labRawCounts
        prepared_fixture_vector = $fixtureVector
        prepared_piwigo_container_id = $piwigoId
        drill = $null
    }
    Write-Output 'PRIVATE_E2E_RECREATE_LAB=PASS action=prepare owner=UNCHANGED database=LOCKED_STREAM_CLONE piwigo=SMALL_CONTROL_ONLY ports=NONE'
}

function New-RunMarker {
    $bytes = [byte[]]::new(12)
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return -join ($bytes | ForEach-Object { $_.ToString('x2') })
}

function Start-LabBroker([string]$Run, [string]$CredentialPath) {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_LEASE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $Run),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_CREDENTIAL_FILE=' + $CredentialPath),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS=' + $leaseTtlSeconds),
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php', $Run
    )
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $wsl
    $info.Arguments = ((Get-LabComposeArguments $tail) | ForEach-Object { ConvertTo-ClassArchiveWin32Argument ([string]$_) }) -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $info.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-RecreateLab 'lab_broker_start_failed' }
    [void]$process.StandardError.ReadToEndAsync() # drain and deliberately never reflect
    $readyTask = $process.StandardOutput.ReadLineAsync()
    if (-not $readyTask.Wait([TimeSpan]::FromSeconds(90))) {
        try { $process.StandardInput.Close() } catch {}
        try { $process.Kill() } catch {}
        $process.Dispose()
        Stop-RecreateLab 'lab_broker_ready_timeout'
    }
    if ([string]$readyTask.Result -ne ('V4_OWNER_FQA_LEASE=READY roles=3 ttl=' + $leaseTtlSeconds)) {
        try { $process.StandardInput.Close() } catch {}
        try { $process.Kill() } catch {}
        $process.Dispose()
        Stop-RecreateLab 'lab_broker_not_ready'
    }
    return $process
}

function Invoke-Drill {
    if (-not $ConfirmLabSigkill) { Stop-RecreateLab 'lab_sigkill_confirmation_required' }
    Assert-OwnerEnvFile
    Assert-OwnerRuntime
    Assert-LabRuntimeObjects
    $state = Read-LabState
    if ([string]$state.project -ne $labProject -or [string]$state.scope -ne $labScope -or $null -ne $state.drill) {
        Stop-RecreateLab 'lab_state_not_drillable'
    }
    if ((Get-DumpDigest $ownerDbContainer) -ne [string]$state.owner_dump_sha256 -or
        (Get-BusinessCountVector $ownerDbContainer) -ne [string]$state.owner_count_vector) {
        Stop-RecreateLab 'owner_drift_before_drill'
    }

    $before = Get-LabFixtureVector
    $beforeParts = @($before -split '\|')
    $auditBefore = Get-LabAuditEvidence
    $auditBeforeParts = @($auditBefore -split '\|')
    if ($beforeParts[1] -ne 'FROZEN') { Stop-RecreateLab 'lab_fixture_not_frozen_before_drill' }
    $run = New-RunMarker
    $credentialPath = '/var/lib/class-archive-private-e2e/credentials-' + $run.Substring(0, 16) + '.json'
    $beforeContainerIds = @(Invoke-Docker @('inspect', '--format', '{{.Id}}', ($labProject + '-piwigo-1')) 'lab_container_id_failed' 30 -Capture)
    if ($beforeContainerIds.Count -ne 1) { Stop-RecreateLab 'lab_container_id_ambiguous' }
    $beforeContainerId = $beforeContainerIds[0]
    $broker = Start-LabBroker $run $credentialPath
    try {
        $open = Get-LabFixtureVector
        $openParts = @($open -split '\|')
        $leaseOpen = Get-LabLeaseEvidence $run
        $leaseOpenParts = @($leaseOpen -split '\|')
        $auditOpen = Get-LabAuditEvidence
        $auditOpenParts = @($auditOpen -split '\|')
        if ($openParts[1] -ne 'ACTIVE' -or [int]$openParts[2] -ne ([int]$beforeParts[2] + 1) -or
            $leaseOpenParts[0] -ne 'ACTIVE' -or
            [int]$auditOpenParts[0] -ne ([int]$auditBeforeParts[0] + 1) -or
            [int]$auditOpenParts[1] -ne [int]$auditBeforeParts[1] -or
            [int]$auditOpenParts[2] -ne ([int]$auditBeforeParts[2] + 3) -or
            [int]$auditOpenParts[3] -ne [int]$auditBeforeParts[3]) {
            Stop-RecreateLab 'lab_open_evidence_invalid'
        }

        # Failure injection is impossible until the exact target is re-read.
        Assert-ContainerBinding ($labProject + '-piwigo-1') $labProject 'piwigo' $labScope
        Invoke-LabCompose @('kill', '--signal', 'SIGKILL', 'piwigo') 'lab_sigkill_failed' 30 | Out-Null
        if (-not $broker.WaitForExit(45000)) {
            try { $broker.Kill() } catch {}
            Stop-RecreateLab 'lab_broker_survived_container_kill'
        }

        # Direct expiry is bounded to the exact run in the isolated clone. It
        # avoids a five-minute wait without weakening production lease logic.
        $expireProgram = @'
docker exec class_archive_private_e2e_recreate_lab-db-1 sh -eu -c '
  export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"
  prefix=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "SELECT LEFT(TABLE_NAME,LENGTH(TABLE_NAME)-LENGTH(\"class_identity_identity\")) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE \"%class_identity_identity\"")
  test -n "$prefix"; case "$prefix" in *[!A-Za-z0-9_]*) exit 31 ;; esac
  changed=$(mariadb --batch --skip-column-names --host=127.0.0.1 --user=root "$MARIADB_DATABASE" -e \
    "UPDATE \`${prefix}class_identity_private_e2e_fixture_lease\` SET expires_at=TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(6)),heartbeat_at=TIMESTAMPADD(SECOND,-601,UTC_TIMESTAMP(6)) WHERE test_run_id=\"__RUN__\" AND fixture_owner=\"v4-owner-fqa-broker\" AND state=\"ACTIVE\"; SELECT ROW_COUNT()")
  test "$changed" = 1
'
'@.Replace('__RUN__', $run)
        Invoke-Bash $expireProgram 'lab_exact_lease_expiry_failed' 60 | Out-Null

        Invoke-LabCompose @('rm', '-f', '-s', 'piwigo') 'lab_killed_container_remove_failed' 60 | Out-Null
        Invoke-LabCompose @('up', '-d', '--force-recreate', 'piwigo') 'lab_piwigo_recreate_failed' 180 | Out-Null
        Wait-LabHealthy 'piwigo' 150
        Assert-LabRuntimeObjects
        $afterContainerIds = @(Invoke-Docker @('inspect', '--format', '{{.Id}}', ($labProject + '-piwigo-1')) 'lab_recreated_container_id_failed' 30 -Capture)
        if ($afterContainerIds.Count -ne 1) { Stop-RecreateLab 'lab_recreated_container_id_ambiguous' }
        $afterContainerId = $afterContainerIds[0]
        if ($afterContainerId -eq $beforeContainerId) { Stop-RecreateLab 'lab_container_not_recreated' }
        Invoke-LabCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'test', '-f', $credentialPath) `
            'lab_recovery_plan_not_durable' 30 | Out-Null

        $recovery = @(Invoke-LabCompose @(
            'exec', '-T', '--user', 'nginx',
            '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
            '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1',
            '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $run),
            'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php', $run
        ) 'lab_broker_recovery_failed' 180 -Capture)
        if ($recovery.Count -ne 1 -or $recovery[0] -ne 'V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked') {
            Stop-RecreateLab 'lab_broker_recovery_attestation_invalid'
        }
        $planStillExists = Invoke-LabCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'sh', '-c', ('test ! -e ' + $credentialPath)) `
            'lab_recovery_plan_not_removed' 30

        $after = Get-LabFixtureVector
        $afterParts = @($after -split '\|')
        $leaseAfter = Get-LabLeaseEvidence $run
        $leaseAfterParts = @($leaseAfter -split '\|')
        $leaseLineage = Get-LabLeaseLineageEvidence $run
        $auditAfter = Get-LabAuditEvidence
        $auditAfterParts = @($auditAfter -split '\|')
        if ($afterParts[1] -ne 'FROZEN' -or [int]$afterParts[2] -ne ([int]$beforeParts[2] + 2) -or
            [int]$afterParts[3] -ne ([int]$beforeParts[3] + 3) -or [int]$afterParts[4] -ne 0 -or
            $leaseAfterParts[0] -ne 'RELEASED' -or
            $leaseLineage -ne '1|1|1' -or
            [int]$auditAfterParts[0] -ne ([int]$auditBeforeParts[0] + 1) -or
            [int]$auditAfterParts[1] -ne ([int]$auditBeforeParts[1] + 1) -or
            [int]$auditAfterParts[2] -ne ([int]$auditBeforeParts[2] + 3) -or
            [int]$auditAfterParts[3] -ne ([int]$auditBeforeParts[3] + 3)) {
            Stop-RecreateLab 'lab_terminal_recovery_evidence_invalid'
        }
        if ((Get-DumpDigest $ownerDbContainer) -ne [string]$state.owner_dump_sha256 -or
            (Get-BusinessCountVector $ownerDbContainer) -ne [string]$state.owner_count_vector) {
            Stop-RecreateLab 'owner_changed_during_drill'
        }
        $updated = @{
            version = 1
            project = [string]$state.project
            scope = [string]$state.scope
            prepared_at = [string]$state.prepared_at
            owner_dump_sha256 = [string]$state.owner_dump_sha256
            owner_count_vector = [string]$state.owner_count_vector
            raw_clone_dump_sha256 = [string]$state.raw_clone_dump_sha256
            raw_clone_count_vector = [string]$state.raw_clone_count_vector
            prepared_fixture_vector = [string]$state.prepared_fixture_vector
            prepared_piwigo_container_id = [string]$state.prepared_piwigo_container_id
            drill = @{
                completed_at = [DateTime]::UtcNow.ToString('o')
                run_marker = $run
                before = $before
                open = $open
                terminal = $after
                audit_before = $auditBefore
                audit_open = $auditOpen
                audit_terminal = $auditAfter
                lease_terminal = $leaseAfter
                lease_lineage = $leaseLineage
                old_container_id = $beforeContainerId
                new_container_id = $afterContainerId
                signal = 'SIGKILL'
                recovery_plan = 'PERSISTED_ACROSS_RECREATE_THEN_REMOVED'
                owner_unchanged = $true
            }
        }
        Write-LabState $updated
        Write-Output 'PRIVATE_E2E_RECREATE_LAB=PASS action=drill broker=REAL_ADMIN_AUDIT_CORE_REVOKE signal=SIGKILL recreate=NEW_CONTAINER recovery=DURABLE_PLAN identity=FROZEN lease=RELEASED owner=UNCHANGED'
    } finally {
        if ($null -ne $broker) {
            try { if (-not $broker.HasExited) { $broker.Kill() } } catch {}
            $broker.Dispose()
        }
    }
}

function Invoke-Verify {
    Assert-OwnerEnvFile
    Assert-OwnerRuntime
    Assert-LabRuntimeObjects
    $state = Read-LabState
    if ($null -eq $state.drill -or [string]$state.drill.signal -ne 'SIGKILL' -or
        [string]$state.drill.recovery_plan -ne 'PERSISTED_ACROSS_RECREATE_THEN_REMOVED') {
        Stop-RecreateLab 'lab_drill_evidence_missing'
    }
    if ((Get-DumpDigest $ownerDbContainer) -ne [string]$state.owner_dump_sha256 -or
        (Get-BusinessCountVector $ownerDbContainer) -ne [string]$state.owner_count_vector) {
        Stop-RecreateLab 'owner_drift_after_drill'
    }
    $current = Get-LabFixtureVector
    if (($current -split '\|')[1] -ne 'FROZEN') { Stop-RecreateLab 'lab_fixture_not_terminal' }
    $lease = Get-LabLeaseEvidence ([string]$state.drill.run_marker)
    if (($lease -split '\|')[0] -ne 'RELEASED') { Stop-RecreateLab 'lab_lease_not_terminal' }
    Write-Output 'PRIVATE_E2E_RECREATE_LAB=PASS action=verify owner=UNCHANGED identity=FROZEN lease=RELEASED ports=NONE'
}

function Invoke-Cleanup {
    if (-not $ConfirmLabCleanup) { Stop-RecreateLab 'lab_cleanup_confirmation_required' }
    Assert-CleanupScope
    Invoke-LabCompose @('down', '--volumes', '--remove-orphans', '--timeout', '15') 'lab_cleanup_failed' 180 | Out-Null
    $remaining = @(Invoke-Docker @('ps', '-a', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.ID}}') `
        'lab_cleanup_container_verify_failed' 30 -Capture)
    $remainingVolumes = @(Invoke-Docker @('volume', 'ls', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Name}}') `
        'lab_cleanup_volume_verify_failed' 30 -Capture)
    $remainingNetworks = @(Invoke-Docker @('network', 'ls', '--filter', ('label=com.classarchive.scope=' + $labScope), '--format', '{{.Name}}') `
        'lab_cleanup_network_verify_failed' 30 -Capture)
    if ($remaining.Count -ne 0 -or $remainingVolumes.Count -ne 0 -or $remainingNetworks.Count -ne 0) { Stop-RecreateLab 'lab_cleanup_incomplete' }
    if (Test-Path -LiteralPath $runtimeRoot) {
        $resolved = [IO.Path]::GetFullPath($runtimeRoot)
        $expected = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-e2e-recreate-lab'))
        if (-not [string]::Equals($resolved, $expected, [StringComparison]::OrdinalIgnoreCase)) {
            Stop-RecreateLab 'runtime_cleanup_target_invalid'
        }
        Remove-Item -LiteralPath $resolved -Recurse -Force
    }
    Write-Output 'PRIVATE_E2E_RECREATE_LAB=PASS action=cleanup scope=EXACT_LABEL_AND_PREFIX owner=UNTOUCHED'
}

try {
    switch ($Action) {
        'validate' { Invoke-Validate }
        'config' { Invoke-Validate }
        'prepare' { Invoke-Prepare }
        'drill' { Invoke-Drill }
        'verify' { Invoke-Verify }
        'cleanup' { Invoke-Cleanup }
    }
} catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match 'PRIVATE_E2E_RECREATE_LAB_STOP:([a-z0-9_]+)') {
        $Matches[1]
    }
    else {
        # Keep diagnostics useful without reflecting exception messages that
        # could contain a native command line or expanded private env value.
        $type = $_.Exception.GetType().Name.ToLowerInvariant() -replace '[^a-z0-9]+', '_'
        if ([string]::IsNullOrWhiteSpace($type)) { $type = 'unknown' }
        $line = [int]$_.InvocationInfo.ScriptLineNumber
        'unexpected_' + $type + '_line_' + [Math]::Max(0, $line)
    }
    Write-Output ('PRIVATE_E2E_RECREATE_LAB=BLOCKED action=' + $Action + ' code=' + $code)
    exit 1
}
