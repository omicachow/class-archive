[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Probe', 'Snapshot', 'Migrate', 'Validate')]
    [string]$Action,
    [Parameter(Mandatory = $true)]
    [ValidateSet('owner')]
    [string]$Endpoint,
    [switch]$ConfirmOwnerV16ToV18Migration,
    [string]$MigrationPlanName,
    [string]$V4AcceptanceGateName
)

# Exact Owner-only V16 -> V18 adapter.  Keep the existing V17 -> V18 adapter
# narrow: it must never silently treat a V16 source as V17.  Current
# Schema::migrate() executes reviewed additive migrations 17 then 18.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$baselineHelper = Join-Path $PSScriptRoot 'capture-private-v16-to-v18-migration-baseline.ps1'
$attestationHelper = Join-Path $PSScriptRoot 'attest-v4-synthetic-phase-ab.ps1'
$directProofAttestationHelper = Join-Path $PSScriptRoot 'attest-v16-to-v18-synthetic-direct-runtime.ps1'
$boundedNativeHelper = Join-Path $PSScriptRoot 'class-archive-bounded-native-process.ps1'
$planRoot = Join-Path $projectRoot '.codex-work\private-real-full\migration-v16-to-v18'
$lockPath = Join-Path $projectRoot '.codex-work\private-real-full\runtime\class-v16-to-v18-owner-migration.lock'
$sourceVersion = 16
$targetVersion = 18
$rollbackSchemaCommit = 'd6f15c7bd366d9dcf7fc8792b50d0965a8ee33d4'
$immichEnvWindows = Join-Path $projectRoot 'infra\private-full\.env.immich.owner'
$immichCompose = $null
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-V16ToV18([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_V16_TO_V18_OWNER_STOP:' + $Code)
}
if (-not (Test-Path -LiteralPath $boundedNativeHelper -PathType Leaf)) { Stop-V16ToV18 'bounded_native_helper_missing' }
. $boundedNativeHelper
function Get-FileSha256([string]$Path) {
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
    catch {
        Stop-V16ToV18 'file_hash_runtime_failed'
    }
    if ([string]$hash -notmatch '^[a-fA-F0-9]{64}$') { Stop-V16ToV18 'file_hash_result_invalid' }
    return ([string]$hash).ToLowerInvariant()
}
function Invoke-Wsl([string[]]$CommandArguments, [string]$Code, [switch]$Capture, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    try {
        # `$Args` is PowerShell's automatic collection of unbound arguments.
        # Using it as a formal parameter silently loses the Compose payload in
        # Windows PowerShell 5.1, which must fail closed rather than running an
        # empty or malformed Docker command against the Owner runtime.
        $boundedArgs = Add-ClassArchiveWslTimeout -Arguments $CommandArguments -TimeoutSeconds $TimeoutSeconds
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $boundedArgs -TimeoutSeconds ($TimeoutSeconds + 15) -WorkingDirectory $projectRoot
    }
    catch { Stop-V16ToV18 ($Code + '_start_failed') }
    if ($result.TimedOut) { Stop-V16ToV18 ($Code + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-V16ToV18 $Code }
    if ($Capture) { return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' }) }
}
function Get-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V16ToV18 'env_file_missing' }
    if ($full -match '[\s\"]' -or $full.Contains("`0")) { Stop-V16ToV18 'wsl_path_invalid' }
    try {
        $boundedArgs = Add-ClassArchiveWslTimeout -Arguments @('-d','Ubuntu','--exec','wslpath','-a',$full) -TimeoutSeconds 15
        $result = Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $boundedArgs -TimeoutSeconds 30 -WorkingDirectory $projectRoot
    }
    catch { Stop-V16ToV18 'wsl_path_invalid' }
    if ($result.TimedOut) { Stop-V16ToV18 'wsl_path_timeout' }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0 -or -not [string]::IsNullOrWhiteSpace([string]$result.Stderr)) { Stop-V16ToV18 'wsl_path_invalid' }
    $lines = @(([string]$result.Stdout -split "`r?`n") | Where-Object { $_ -ne '' })
    if ($lines.Count -ne 1 -or $lines[0] -notmatch '^/mnt/[a-z]/') { Stop-V16ToV18 'wsl_path_invalid' }
    return [string]$lines[0]
}
$piwigoCompose = @('-d','Ubuntu','--cd',$projectRoot,'--','docker','compose','--env-file','infra/private-full/.env.piwigo.owner','-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml','-p','class_archive_private_full_v3_piwigo','--profile','ops')
function Initialize-ImmichCompose {
    # Do not resolve a WSL path while this script is loading. That keeps an
    # unavailable local Docker/WSL runtime inside the normal fail-closed action
    # boundary instead of throwing before the structured error handler exists.
    if ($null -ne $script:immichCompose) { return }
    $immichEnv = Get-WslPath $script:immichEnvWindows
    $script:immichCompose = @('-d','Ubuntu','--cd',$projectRoot,'--','env',('IMMICH_SPIKE_ENV_FILE=' + $immichEnv),'docker','compose','--env-file','infra/private-full/.env.immich.owner','-f','infra/immich-spike/docker-compose.yml','-f','infra/private-full/docker-compose.immich.override.yml','-p','class_archive_private_full_v3_immich','--profile','immich-spike','--profile','immich-ml','--profile','immich-web-compat','--profile','immich-gateway-integration')
}
function Invoke-Piwigo([string[]]$CommandArguments, [switch]$Capture, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    return Invoke-Wsl -CommandArguments @($script:piwigoCompose + $CommandArguments) -Code 'piwigo_compose_failed' -Capture:$Capture -TimeoutSeconds $TimeoutSeconds
}
function Invoke-Immich([string[]]$CommandArguments, [ValidateRange(1,900)][int]$TimeoutSeconds = 180) {
    Initialize-ImmichCompose
    Invoke-Wsl -CommandArguments @($script:immichCompose + $CommandArguments) -Code 'compat_compose_failed' -TimeoutSeconds $TimeoutSeconds
}
function Invoke-ChildPowerShell([string]$ScriptPath, [string[]]$Arguments, [string]$Code, [ValidateRange(1,900)][int]$TimeoutSeconds = 120) {
    if (-not (Test-Path -LiteralPath $ScriptPath -PathType Leaf)) { Stop-V16ToV18 ($Code + '_script_missing') }
    $powershell = Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
    try {
        $result = Invoke-ClassArchiveBoundedNative -Executable $powershell -Arguments (@('-NoProfile','-NonInteractive','-ExecutionPolicy','Bypass','-File',$ScriptPath) + $Arguments) -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $projectRoot
    }
    catch { Stop-V16ToV18 ($Code + '_start_failed') }
    if ($result.TimedOut) { Stop-V16ToV18 ($Code + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-V16ToV18 $Code }
    return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Assert-OwnerTarget {
    if ($Endpoint -ne 'owner') { Stop-V16ToV18 'owner_endpoint_required' }
}
function Assert-CleanCheckout {
    $lines = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>&1)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 0) { Stop-V16ToV18 'migration_checkout_not_clean' }
}
function Get-Head {
    $head = @(& git -C $projectRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $head.Count -ne 1 -or ([string]$head[0]).Trim() -notmatch '^[a-f0-9]{40}$') { Stop-V16ToV18 'git_head_invalid' }
    return ([string]$head[0]).Trim()
}
function Assert-SchemaSource {
    $schema = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    $source = if (Test-Path -LiteralPath $schema -PathType Leaf) { [IO.File]::ReadAllText($schema) } else { '' }
    if ($source -notmatch 'CURRENT_VERSION\s*=\s*18\s*;' -or -not $source.Contains("'name' => '0017_photos_app_v4_collection_snapshots'") -or -not $source.Contains("'name' => '0018_photos_app_v4_spotlight_rotation_state'")) { Stop-V16ToV18 'schema_target_contract_mismatch' }
    $legacy = @(& git -C $projectRoot show ($rollbackSchemaCommit + ':plugins/ClassIdentity/src/Schema.php') 2>$null)
    if ($LASTEXITCODE -ne 0 -or @($legacy | Where-Object { $_ -match 'CURRENT_VERSION\s*=\s*16\s*;' }).Count -ne 1) { Stop-V16ToV18 'rollback_source_v16_unavailable' }
}
function Assert-DockerDesktopEnginePipe {
    # This adapter is Windows/WSL-specific. A loopback listener alone is not
    # enough to permit a state-changing migration: the Docker Desktop Linux
    # engine pipe must exist before any compose action can be attempted.
    $pipes = @('\\.\pipe\dockerDesktopLinuxEngine','\\.\pipe\docker_engine')
    if (@($pipes | Where-Object { Test-Path -LiteralPath $_ }).Count -eq 0) {
        Stop-V16ToV18 'docker_engine_pipe_unavailable'
    }
}
function Assert-OwnerRuntime {
    # Refuse before any Docker/WSL work when Desktop's engine pipe is absent.
    # This also avoids turning a host socket failure into a hung migration.
    Assert-DockerDesktopEnginePipe
    # Check bounded loopback endpoints before deeper lifecycle diagnostics.
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) { Stop-V16ToV18 'owner_loopback_curl_missing' }
    foreach ($uri in @('http://127.0.0.1:8190','http://127.0.0.1:8191')) {
        $prior = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $line = @(& $curl --noproxy '*' --silent --show-error --max-time 15 --output NUL --write-out 'CLASS_ARCHIVE_STATUS:%{http_code}' $uri 2>&1)
            $exit = $LASTEXITCODE
        } finally { $ErrorActionPreference = $prior }
        $match = [regex]::Match(([string]::Join([Environment]::NewLine, $line)), 'CLASS_ARCHIVE_STATUS:(\d{3})\z')
        if ($exit -ne 0 -or -not $match.Success -or [int]$match.Groups[1].Value -notin @(200,301,302,303)) { Stop-V16ToV18 'owner_loopback_endpoint_unhealthy' }
    }
    # Lifecycle diagnostics can contain host-specific runtime details. The
    # migration contract only needs its exit status, so retain no child output
    # and put a hard cap around nested WSL/Docker validation.
    [void](Invoke-ChildPowerShell $lifecycle @('runtime-owner') 'owner_lifecycle_invalid' 240)
}
function Get-SchemaState {
    $lines = Invoke-Piwigo @('run','--rm','-e','CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=16','-e','CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=18','-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe','pre-migration-db-backup') -Capture -TimeoutSeconds 90
    $v16 = 'PRE_MIGRATION_DB_SNAPSHOT=REQUIRED_CURRENT_V16 schema_current=16 schema_from=16 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED'
    $v18 = 'PRE_MIGRATION_DB_SNAPSHOT=NOT_REQUIRED_CURRENT_V18 schema_current=18 schema_from=18 schema_to=18 scope=NONE media=NOT_INCLUDED'
    if (@($lines | Where-Object { $_ -eq $v16 }).Count -eq 1) { return 'V16' }
    if (@($lines | Where-Object { $_ -eq $v18 }).Count -eq 1) { return 'V18' }
    Stop-V16ToV18 'schema_probe_invalid'
}
function Assert-SourceV16 { if ((Get-SchemaState) -ne 'V16') { Stop-V16ToV18 'source_schema_not_exact_v16' } }
function Assert-TargetV18 { if ((Get-SchemaState) -ne 'V18') { Stop-V16ToV18 'target_schema_not_exact_v18' } }

function Wait-Maintenance {
    $deadline = [DateTime]::UtcNow.AddSeconds(90)
    do {
        try { $lines = Invoke-Piwigo @('exec','-T','piwigo','curl','--silent','--show-error','--write-out','CLASS_ARCHIVE_STATUS:%{http_code}','http://127.0.0.1/') -Capture -TimeoutSeconds 10 } catch { $lines = @() }
        if ($lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return }
        Start-Sleep -Seconds 1
    } while ([DateTime]::UtcNow -lt $deadline)
    Stop-V16ToV18 'maintenance_not_ready'
}
function Assert-PiwigoStoppedForSnapshot {
    # Stopping the compose service is not, by itself, proof that the former
    # writer has exited.  The DB-only snapshot must not race a still-running
    # Piwigo process, so inspect the fixed Owner service before starting the
    # snapshot container.  This checks no media and exposes no runtime data.
    $lines = Invoke-Wsl @('-d','Ubuntu','--exec','docker','inspect','--format','{{.State.Running}}|{{.State.Status}}','class_archive_private_full_v3_piwigo-piwigo-1') 'writer_stop_inspection_failed' -Capture -TimeoutSeconds 15
    if ($lines.Count -ne 1 -or $lines[0] -ne 'false|exited') { Stop-V16ToV18 'writer_not_stopped' }
}
function Get-PiwigoWriterStateForRecovery {
    $lines = Invoke-Wsl @('-d','Ubuntu','--exec','docker','inspect','--format','{{.State.Running}}|{{.State.Status}}','class_archive_private_full_v3_piwigo-piwigo-1') 'writer_recovery_inspection_failed' -Capture -TimeoutSeconds 15
    if ($lines.Count -ne 1 -or $lines[0] -notin @('true|running','false|exited')) { Stop-V16ToV18 'writer_recovery_state_invalid' }
    return [string]$lines[0]
}
function Enter-Maintenance {
    Invoke-Piwigo @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') -TimeoutSeconds 60
    Wait-Maintenance
}
function RecreatePiwigoUnderMaintenance {
    Invoke-Piwigo @('up','-d','--force-recreate','--no-deps','piwigo') -TimeoutSeconds 180
    Wait-Maintenance
    Invoke-Piwigo @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare') -TimeoutSeconds 60
}
function Finalize-Maintenance {
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance') -TimeoutSeconds 90
}
function Finalize-SnapshotRecoveryMaintenance {
    # Snapshot has not installed V18 bytes or altered the V16 ledger.  The
    # normal finalizer must therefore remain strict (and reject the historical
    # V16 tree).  This separate, one-purpose finalizer proves the exact locked
    # V16 installation/read-only database state, then performs only the
    # trusted maintenance-marker unlink.
    Invoke-Piwigo @('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_OWNER_V16_SNAPSHOT_RECOVERY=1','piwigo','php','/workspace/infra/scripts/finalize-owner-v16-snapshot-recovery.php','--finalize-owner-v16-snapshot-recovery') -TimeoutSeconds 120
}
function Get-SnapshotMaintenanceState {
    # Snapshot has no schema or archive mutation.  Its recovery path may
    # safely reopen the Owner UI, but only after it has established whether
    # the trusted fail-closed maintenance marker is actually active.  Keep
    # normal response bodies out of the host process; the maintenance body is
    # an exact fixed string and is the only accepted ACTIVE signal.
    try {
        $statusLines = Invoke-Piwigo @('exec','-T','piwigo','curl','--silent','--show-error','--output','/dev/null','--write-out','CLASS_ARCHIVE_STATUS:%{http_code}','http://127.0.0.1/') -Capture -TimeoutSeconds 10
        if ($statusLines.Count -eq 1 -and $statusLines[0] -match '^CLASS_ARCHIVE_STATUS:(2[0-9]{2}|3[0-9]{2})$') { return 'INACTIVE' }
        if ($statusLines.Count -ne 1 -or $statusLines[0] -ne 'CLASS_ARCHIVE_STATUS:503') { return 'UNKNOWN' }
        $lines = Invoke-Piwigo @('exec','-T','piwigo','curl','--silent','--show-error','--output','-','--write-out',"`nCLASS_ARCHIVE_STATUS:%{http_code}",'http://127.0.0.1/') -Capture -TimeoutSeconds 10
        if ($lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return 'ACTIVE' }
        return 'UNKNOWN'
    }
    catch { return 'UNKNOWN' }
}
function Ensure-SnapshotWriterForRecovery {
    # Treat an unreadable writer state as recovery-required rather than as an
    # excuse to leave 8191 unavailable. This runs only in Snapshot's
    # DB-only, pre-migration path; Migrate failures remain fail-closed.
    $state = $null
    try { $state = Get-PiwigoWriterStateForRecovery } catch { $state = $null }
    if ($state -ne 'true|running') {
        Invoke-Piwigo @('up','-d','--force-recreate','--no-deps','piwigo') -TimeoutSeconds 180
    }
    # Docker Desktop can take longer than the ordinary control timeout to
    # reconnect a stopped Owner writer after a host restart.  Snapshot is
    # still DB-only and marker-gated; wait for a real running container rather
    # than abandoning the otherwise unchanged library in maintenance mode.
    $deadline = [DateTime]::UtcNow.AddSeconds(300)
    do {
        try {
            if ((Get-PiwigoWriterStateForRecovery) -eq 'true|running') { return }
        }
        catch { }
        Start-Sleep -Seconds 1
    } while ([DateTime]::UtcNow -lt $deadline)
    Stop-V16ToV18 'snapshot_recovery_writer_not_running'
}
function Restore-SnapshotOwnerAvailability {
    # A Snapshot failure cannot leave the otherwise unchanged Owner runtime
    # locked in maintenance. Re-establish a running writer, finalize only an
    # observed maintenance marker, then demand the normal fail-closed runtime
    # boundary. Do not use this for Migrate: a partial schema change requires
    # explicit manual rollback instead.
    Ensure-SnapshotWriterForRecovery
    $deadline = [DateTime]::UtcNow.AddSeconds(90)
    do {
        $maintenance = Get-SnapshotMaintenanceState
        if ($maintenance -in @('ACTIVE','INACTIVE')) { break }
        Start-Sleep -Seconds 1
    } while ([DateTime]::UtcNow -lt $deadline)
    if ($maintenance -eq 'UNKNOWN') { Stop-V16ToV18 'snapshot_recovery_maintenance_state_unknown' }
    if ($maintenance -eq 'ACTIVE') { Finalize-SnapshotRecoveryMaintenance }
    Assert-OwnerRuntime
}
function Create-PreMigrationSnapshot {
    $writerStopAttempted = $false
    $writerStopped = $false
    try {
        $writerStopAttempted = $true
        Invoke-Piwigo @('stop','piwigo') -TimeoutSeconds 120; $writerStopped = $true
        Assert-PiwigoStoppedForSnapshot
        # Capture the numeric/semantic source evidence after the last Piwigo
        # writer has exited and immediately before the DB-only dump. The helper
        # refuses open AI jobs, so this boundary cannot race a queued index
        # mutation and cannot be mistaken for an atomic backup of media.
        $baseline = Capture-Baseline
        $lines = Invoke-Piwigo @('run','--rm','-e','CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=16','-e','CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=18','-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=snapshot','-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true','pre-migration-db-backup') -Capture -TimeoutSeconds 300
        $pattern = '^PRE_MIGRATION_DB_SNAPSHOT=PASS bundle=(pre-migration-db-v16-to-v18-[0-9]{8}T[0-9]{6}Z) schema_from=16 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED$'
        $records = @($lines | Where-Object { $_ -match $pattern })
        if ($records.Count -ne 1) { Stop-V16ToV18 'pre_migration_snapshot_evidence_invalid' }
        return @{ Name = [regex]::Match($records[0], $pattern).Groups[1].Value; Baseline = $baseline }
    } finally {
        if ($writerStopAttempted) {
            $state = Get-PiwigoWriterStateForRecovery
            if ($state -eq 'false|exited') { RecreatePiwigoUnderMaintenance }
        }
    }
}
function Get-SnapshotBinding([string]$Name) {
    if ($Name -notmatch '^pre-migration-db-v16-to-v18-[0-9]{8}T[0-9]{6}Z$') { Stop-V16ToV18 'snapshot_name_invalid' }
    $script = @'
set -eu
b="$1"
case "$b" in pre-migration-db-v16-to-v18-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) exit 71 ;; esac
directory="/backup/$b"
[ -d "$directory" ] && [ ! -L "$directory" ] || exit 72
cd "$directory"
[ -f COMPLETE ] && [ ! -L COMPLETE ] && [ -f MANIFEST.json ] && [ ! -L MANIFEST.json ] && [ -f SHA256SUMS ] && [ ! -L SHA256SUMS ] && [ -f database.sql.gz ] && [ ! -L database.sql.gz ] || exit 73
sha256sum -c SHA256SUMS >/dev/null
grep -F '"format":1,"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK"' MANIFEST.json >/dev/null
grep -F '"schema_current":16,"schema_from":16,"schema_to":18' MANIFEST.json >/dev/null
grep -F '"media":"NOT_INCLUDED"' MANIFEST.json >/dev/null
manifest_sha256=$(sha256sum MANIFEST.json | awk '{print $1}')
dump_sha256=$(sha256sum database.sql.gz | awk '{print $1}')
dump_bytes=$(wc -c < database.sql.gz | tr -d ' ')
case "$manifest_sha256:$dump_sha256:$dump_bytes" in *[!0-9a-f:]*|:*|*::*) exit 74 ;; esac
grep -F "\"dump_sha256\":\"$dump_sha256\"" MANIFEST.json >/dev/null
grep -F "\"dump_bytes\":$dump_bytes" MANIFEST.json >/dev/null
printf 'PRIVATE_V16_TO_V18_SNAPSHOT_BINDING=PASS bundle=%s manifest_sha256=%s dump_sha256=%s dump_bytes=%s scope=DB_ONLY media=NOT_INCLUDED\n' "$b" "$manifest_sha256" "$dump_sha256" "$dump_bytes"
'@
    $lines = Invoke-Piwigo @('run','--rm','--entrypoint','/bin/sh','pre-migration-db-backup','-eu','-c',$script,'snapshot-binding',$Name) -Capture -TimeoutSeconds 120
    $pattern = '^PRIVATE_V16_TO_V18_SNAPSHOT_BINDING=PASS bundle=' + [regex]::Escape($Name) + ' manifest_sha256=([a-f0-9]{64}) dump_sha256=([a-f0-9]{64}) dump_bytes=([1-9][0-9]*) scope=DB_ONLY media=NOT_INCLUDED$'
    $records = @($lines | Where-Object { $_ -match $pattern })
    if ($records.Count -ne 1) { Stop-V16ToV18 'snapshot_binding_evidence_invalid' }
    $match = [regex]::Match($records[0], $pattern)
    return @{ Name=$Name; ManifestSha256=$match.Groups[1].Value; DumpSha256=$match.Groups[2].Value; DumpBytes=$match.Groups[3].Value }
}
function Assert-Snapshot([hashtable]$Snapshot) {
    if ($null -eq $Snapshot -or [string]$Snapshot.Name -notmatch '^pre-migration-db-v16-to-v18-[0-9]{8}T[0-9]{6}Z$' -or [string]$Snapshot.ManifestSha256 -notmatch '^[a-f0-9]{64}$' -or [string]$Snapshot.DumpSha256 -notmatch '^[a-f0-9]{64}$' -or [string]$Snapshot.DumpBytes -notmatch '^[1-9][0-9]*$') { Stop-V16ToV18 'snapshot_reference_invalid' }
    $actual = Get-SnapshotBinding $Snapshot.Name
    foreach ($key in @('ManifestSha256','DumpSha256','DumpBytes')) {
        if (-not [string]::Equals([string]$actual[$key],[string]$Snapshot[$key],[StringComparison]::Ordinal)) { Stop-V16ToV18 'snapshot_binding_mismatch' }
    }
}

function Invoke-Baseline([string[]]$CommandArguments,[string]$Code) {
    if (-not (Test-Path -LiteralPath $baselineHelper -PathType Leaf)) { Stop-V16ToV18 'baseline_helper_missing' }
    return Invoke-ChildPowerShell $baselineHelper $CommandArguments $Code 600
}
function Assert-Baseline([hashtable]$Baseline) {
    if ($null -eq $Baseline -or [string]$Baseline.Name -notmatch '^owner-v16-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json$' -or [string]$Baseline.Sha256 -notmatch '^[a-f0-9]{64}$') { Stop-V16ToV18 'baseline_reference_invalid' }
}
function Capture-Baseline {
    $lines = Invoke-Baseline @('-Action','capture','-Endpoint','owner','-ExpectedSchema','16') 'baseline_capture_failed'
    $pattern = '^PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=capture endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=(owner-v16-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json) sha256=([a-f0-9]{64}) privacy=COUNTS_AND_OPAQUE_HASHES_ONLY media=NOT_MOUNTED$'
    $rows = @($lines | Where-Object { $_ -match $pattern })
    if ($rows.Count -ne 1) { Stop-V16ToV18 'baseline_capture_evidence_invalid' }
    $match = [regex]::Match($rows[0],$pattern)
    return @{ Name=$match.Groups[1].Value; Sha256=$match.Groups[2].Value }
}
function Assert-SourceBaseline([hashtable]$Baseline) {
    Assert-Baseline $Baseline
    $lines = Invoke-Baseline @('-Action','verify-source','-Endpoint','owner','-ExpectedSchema','16','-BaselineName',$Baseline.Name,'-ExpectedSha256',$Baseline.Sha256) 'source_baseline_validation_failed'
    $expected = 'PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=verify-source endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=' + $Baseline.Name + ' sha256=' + $Baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED media=NOT_MOUNTED'
    if (@($lines | Where-Object { $_ -eq $expected }).Count -ne 1) { Stop-V16ToV18 'source_baseline_evidence_invalid' }
}
function Compare-Baseline([hashtable]$Baseline) {
    Assert-Baseline $Baseline
    $lines = Invoke-Baseline @('-Action','compare','-Endpoint','owner','-ExpectedSchema','18','-BaselineName',$Baseline.Name,'-ExpectedSha256',$Baseline.Sha256) 'post_migration_count_validation_failed'
    $expected = 'PRIVATE_V16_TO_V18_NUMERIC_BASELINE=PASS action=compare endpoint=owner ports=8190_8191 source_schema=16 target_schema=18 baseline=' + $Baseline.Name + ' sha256=' + $Baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED v17_v18_expansion=STRUCTURALLY_VALID rotation=IDLE_OR_OPERATIONAL media=NOT_MOUNTED'
    if (@($lines | Where-Object { $_ -eq $expected }).Count -ne 1) { Stop-V16ToV18 'post_migration_count_evidence_invalid' }
}

function Assert-IgnoredPlanRoot {
    [void][IO.Directory]::CreateDirectory($planRoot)
    $item = Get-Item -LiteralPath $planRoot -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V16ToV18 'plan_directory_untrusted' }
    $relative = $item.FullName.Substring($projectRoot.TrimEnd('\','/').Length).TrimStart('\','/').Replace('\','/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -ne 0) { Stop-V16ToV18 'plan_directory_not_ignored' }
    return $item.FullName
}
function Invoke-V4Gate([string]$Name) {
    if ([string]::IsNullOrWhiteSpace($Name) -or -not (Test-Path -LiteralPath $attestationHelper -PathType Leaf)) { Stop-V16ToV18 'v4_acceptance_gate_missing' }
    $lines = Invoke-ChildPowerShell $attestationHelper @('-Action','Verify','-GateName',$Name) 'v4_acceptance_gate_invalid' 60
    $pattern = '^V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Verify gate=(v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json) sha256=([a-f0-9]{64}) scope=SYNTHETIC_8091 browser=GOOGLE_CHROME_STABLE media=MEDIAGUARD_REGRESSION$'
    $rows = @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -match $pattern })
    if ($rows.Count -ne 1 -or [regex]::Match($rows[0],$pattern).Groups[1].Value -ne $Name) { Stop-V16ToV18 'v4_acceptance_gate_evidence_invalid' }
    $match = [regex]::Match($rows[0],$pattern)
    return @{ Name=$match.Groups[1].Value; Sha256=$match.Groups[2].Value }
}
function Invoke-DirectV16ToV18ProofGate {
    if (-not (Test-Path -LiteralPath $directProofAttestationHelper -PathType Leaf)) { Stop-V16ToV18 'direct_runtime_proof_gate_missing' }
    $lines = Invoke-ChildPowerShell $directProofAttestationHelper @('-Action','verify') 'direct_runtime_proof_gate_invalid' 60
    $pattern = '^V16_TO_V18_SYNTHETIC_DIRECT_ATTESTATION=PASS action=verify commit=([a-f0-9]{40}) source_digest=([a-f0-9]{64}) proof_sha256=([a-f0-9]{64}) attempt=attempt36 media=NOT_MOUNTED$'
    $rows = @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -match $pattern })
    if ($rows.Count -ne 1) { Stop-V16ToV18 'direct_runtime_proof_gate_evidence_invalid' }
    $match = [regex]::Match($rows[0],$pattern)
    if ([string]$match.Groups[1].Value -ne (Get-Head)) { Stop-V16ToV18 'direct_runtime_proof_gate_head_stale' }
    return @{ Commit=$match.Groups[1].Value; SourceDigest=$match.Groups[2].Value; ProofSha256=$match.Groups[3].Value }
}
function Write-Plan([hashtable]$Baseline,[hashtable]$Snapshot,[hashtable]$Gate,[hashtable]$DirectProof) {
    Assert-Baseline $Baseline; Assert-Snapshot $Snapshot
    if ($null -eq $DirectProof -or [string]$DirectProof.Commit -ne (Get-Head) -or [string]$DirectProof.SourceDigest -notmatch '^[a-f0-9]{64}$' -or [string]$DirectProof.ProofSha256 -notmatch '^[a-f0-9]{64}$') { Stop-V16ToV18 'direct_runtime_proof_reference_invalid' }
    $name = 'owner-v16-to-v18-plan-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '.json'
    $path = Join-Path (Assert-IgnoredPlanRoot) $name
    if (Test-Path -LiteralPath $path) { Stop-V16ToV18 'migration_plan_already_exists' }
    $schema = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    $record = [ordered]@{ format=1; scope='OWNER_V16_TO_V18_MIGRATION_PLAN'; created_at=(Get-Date).ToUniversalTime().ToString('o'); source_head=Get-Head; schema_source_sha256=(Get-FileSha256 $schema); source_schema=16; target_schema=18; sequential_migrations=@('0017_photos_app_v4_collection_snapshots','0018_photos_app_v4_spotlight_rotation_state'); rollback_schema_commit=$rollbackSchemaCommit; baseline=[ordered]@{name=$Baseline.Name;sha256=$Baseline.Sha256}; snapshot=[ordered]@{name=$Snapshot.Name;manifest_sha256=$Snapshot.ManifestSha256;dump_sha256=$Snapshot.DumpSha256;dump_bytes=$Snapshot.DumpBytes}; v4_acceptance=[ordered]@{gate=$Gate.Name;sha256=$Gate.Sha256}; direct_v16_to_v18_proof=[ordered]@{commit=$DirectProof.Commit;source_digest=$DirectProof.SourceDigest;proof_sha256=$DirectProof.ProofSha256}; privacy='OPAQUE_LEAF_NAMES_AND_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_MEDIA_OR_SECRETS' }
    [IO.File]::WriteAllText($path,(($record | ConvertTo-Json -Depth 6 -Compress) + [Environment]::NewLine),[Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $path; Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    return @{ Name=$name; Sha256=(Get-FileSha256 $path) }
}
function Read-Plan([string]$Name,[string]$GateName) {
    if ($Name -notmatch '^owner-v16-to-v18-plan-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-V16ToV18 'migration_plan_name_invalid' }
    $path = Join-Path (Assert-IgnoredPlanRoot) $Name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-V16ToV18 'migration_plan_missing' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    try { $plan = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop } catch { Stop-V16ToV18 'migration_plan_json_invalid' }
    if ([int]$plan.format -ne 1 -or [string]$plan.scope -ne 'OWNER_V16_TO_V18_MIGRATION_PLAN' -or [int]$plan.source_schema -ne 16 -or [int]$plan.target_schema -ne 18 -or [string]$plan.source_head -ne (Get-Head) -or [string]$plan.rollback_schema_commit -ne $rollbackSchemaCommit -or [string]$plan.v4_acceptance.gate -ne $GateName) { Stop-V16ToV18 'migration_plan_contract_invalid' }
    $schema = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    if ([string]$plan.schema_source_sha256 -ne (Get-FileSha256 $schema)) { Stop-V16ToV18 'migration_plan_schema_source_stale' }
    $gate = Invoke-V4Gate $GateName
    if ([string]$plan.v4_acceptance.sha256 -ne [string]$gate.Sha256) { Stop-V16ToV18 'migration_plan_acceptance_gate_stale' }
    $directProof = @{ Commit=[string]$plan.direct_v16_to_v18_proof.commit; SourceDigest=[string]$plan.direct_v16_to_v18_proof.source_digest; ProofSha256=[string]$plan.direct_v16_to_v18_proof.proof_sha256 }
    $currentDirectProof = Invoke-DirectV16ToV18ProofGate
    foreach ($key in @('Commit','SourceDigest','ProofSha256')) {
        if (-not [string]::Equals([string]$directProof[$key],[string]$currentDirectProof[$key],[StringComparison]::Ordinal)) { Stop-V16ToV18 'migration_plan_direct_runtime_proof_stale' }
    }
    $baseline=@{Name=[string]$plan.baseline.name;Sha256=[string]$plan.baseline.sha256}
    $snapshot=@{Name=[string]$plan.snapshot.name;ManifestSha256=[string]$plan.snapshot.manifest_sha256;DumpSha256=[string]$plan.snapshot.dump_sha256;DumpBytes=[string]$plan.snapshot.dump_bytes}
    Assert-Baseline $baseline; Assert-Snapshot $snapshot
    return @{Name=$Name;Sha256=(Get-FileSha256 $path);Baseline=$baseline;Snapshot=$snapshot;DirectProof=$currentDirectProof}
}

function Install-Migrations {
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php') -TimeoutSeconds 180
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php') -TimeoutSeconds 180
    Invoke-Piwigo @('exec','-T','--user','root','piwigo','/bin/ash','/workspace/infra/scripts/restore-piwigo-user-script.sh') -TimeoutSeconds 90
    RecreatePiwigoUnderMaintenance
    Verify-ClassIdentityRuntime
}
function Verify-ClassIdentityRuntime {
    # Verification is deliberately separate from installation so Validate and
    # an idempotent replay can re-check the exact V18 runtime contract without
    # opening a write path or rebuilding any AI artifact.
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--verify-runtime') -TimeoutSeconds 90
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php','--verify-only') -TimeoutSeconds 90
}
function Refresh-ReadProjection {
    Invoke-Piwigo @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json') -TimeoutSeconds 600
    Invoke-Immich @('up','-d','--wait','--wait-timeout','60','--force-recreate','immich-web-compat') -TimeoutSeconds 180
}

$lock = $null
try {
    Assert-OwnerTarget; Assert-SchemaSource
    if ($Action -in @('Snapshot','Migrate') -and -not $ConfirmOwnerV16ToV18Migration) { Stop-V16ToV18 'owner_confirmation_required' }
    if ($Action -eq 'Probe') {
        Assert-OwnerRuntime; $state = Get-SchemaState
        $status = if ($state -eq 'V16') { 'READY_SEQUENTIAL_17_THEN_18' } else { 'ALREADY_CURRENT_V18' }
        Write-Output ('PRIVATE_V16_TO_V18_OWNER_MIGRATION=PASS action=Probe endpoint=owner ports=8190_8191 schema_from=16 schema_to=18 state=' + $status + ' media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED manual_rollback_required')
        return
    }
    if ($Action -eq 'Validate') {
        if ([string]::IsNullOrWhiteSpace($MigrationPlanName) -or [string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V16ToV18 'migration_plan_or_v4_gate_required' }
        Assert-CleanCheckout; Assert-OwnerRuntime; $plan=Read-Plan $MigrationPlanName $V4AcceptanceGateName; Assert-TargetV18; Verify-ClassIdentityRuntime; Compare-Baseline $plan.Baseline
        Write-Output ('PRIVATE_V16_TO_V18_OWNER_MIGRATION=PASS action=Validate endpoint=owner ports=8190_8191 schema_from=16 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $plan.Snapshot.Name + ' counts=PRESERVED semantics=PRESERVED media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED manual_rollback_required')
        return
    }
    if ($Action -eq 'Snapshot') {
        if ([string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V16ToV18 'v4_acceptance_gate_required' }
        Assert-CleanCheckout; $gate=Invoke-V4Gate $V4AcceptanceGateName; $directProof=Invoke-DirectV16ToV18ProofGate; Assert-OwnerRuntime; Assert-SourceV16
        $lock=Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
        # Set this before publishing maintenance: even a timeout part way
        # through Enter-Maintenance must not strand the private Owner UI.
        $snapshotRecoveryPending=$true
        try {
            Enter-Maintenance; Assert-SourceV16
            $captured=Create-PreMigrationSnapshot; $baseline=$captured.Baseline; $snapshotName=$captured.Name; $snapshot=Get-SnapshotBinding $snapshotName; Assert-SourceV16; Assert-SourceBaseline $baseline; $plan=Write-Plan $baseline $snapshot $gate $directProof
            Finalize-SnapshotRecoveryMaintenance; Assert-OwnerRuntime
            $snapshotRecoveryPending=$false
            Write-Output ('PRIVATE_V16_TO_V18_OWNER_MIGRATION=PASS action=Snapshot endpoint=owner ports=8190_8191 schema_from=16 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $snapshot.Name + ' snapshot_manifest_sha256=' + $snapshot.ManifestSha256 + ' baseline=' + $baseline.Name + ' baseline_sha256=' + $baseline.Sha256 + ' scope=DB_ONLY media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
            return
        }
        finally {
            if ($snapshotRecoveryPending) { Restore-SnapshotOwnerAvailability }
        }
    }
    if ([string]::IsNullOrWhiteSpace($MigrationPlanName) -or [string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V16ToV18 'migration_plan_or_v4_gate_required' }
    Assert-CleanCheckout; Assert-OwnerRuntime; $state=Get-SchemaState; $plan=Read-Plan $MigrationPlanName $V4AcceptanceGateName
    if ($state -eq 'V18') {
        Assert-TargetV18; Verify-ClassIdentityRuntime; Compare-Baseline $plan.Baseline
        Write-Output ('PRIVATE_V16_TO_V18_OWNER_MIGRATION=PASS action=Migrate endpoint=owner ports=8190_8191 schema_from=18 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' idempotent_replay=PASS maintenance=NOT_ENTERED projection=UNCHANGED bff=UNCHANGED media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
        return
    }
    Assert-SourceV16; Assert-SourceBaseline $plan.Baseline; Assert-Snapshot $plan.Snapshot
    $lock=Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath; Enter-Maintenance; Assert-SourceV16; Assert-SourceBaseline $plan.Baseline; Assert-Snapshot $plan.Snapshot
    Install-Migrations; Assert-TargetV18; Refresh-ReadProjection; Compare-Baseline $plan.Baseline; Finalize-Maintenance; Verify-ClassIdentityRuntime; Assert-OwnerRuntime
    Write-Output ('PRIVATE_V16_TO_V18_OWNER_MIGRATION=PASS action=Migrate endpoint=owner ports=8190_8191 schema_from=16 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $plan.Snapshot.Name + ' baseline=' + $plan.Baseline.Name + ' projection=REBUILT bff=COMPAT_ONLY counts=PRESERVED semantics=PRESERVED media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
}
catch {
    $message=[string]$_.Exception.Message
    $code=if ($message -match '^PRIVATE_V16_TO_V18_OWNER_STOP:([a-z0-9_]{1,96})$') { $Matches[1] } else { 'private_v16_to_v18_owner_migration_failed' }
    if ($code -eq 'private_v16_to_v18_owner_migration_failed') { $code='unexpected_' + $_.Exception.GetType().Name.ToLowerInvariant() + '_line_' + [string]$_.InvocationInfo.ScriptLineNumber }
    Write-Output "PRIVATE_V16_TO_V18_OWNER_MIGRATION=FAIL action=$Action endpoint=$Endpoint code=$code manual_rollback_required"
    exit 2
}
finally { Exit-ClassArchivePluginWorkflowLock -Handle $lock }
