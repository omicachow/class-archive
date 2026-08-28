[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Probe', 'Snapshot', 'Migrate', 'Validate')]
    [string]$Action,

    # Never infer an endpoint. This release is deliberately bound to the
    # private Owner listener pair and rejects every other selector.
    [Parameter(Mandatory = $true)]
    [ValidateSet('owner')]
    [string]$Endpoint,

    [switch]$ConfirmOwnerV18Migration,

    # Snapshot writes a private, hash-bound migration plan. Migrate and
    # Validate consume that exact leaf token rather than accepting a mutable
    # baseline name or a host path.
    [string]$MigrationPlanName,

    # Both state-changing actions require a verified Phase A/B Synthetic 8091
    # Chrome + MediaGuard + cold-restart attestation. The leaf token is local
    # and ignored; it never contains credentials, media, or source paths.
    [string]$V4AcceptanceGateName
)

# Controlled Owner-only ClassIdentity V17 -> V18 migration.
#
# This script is intentionally separate from the historical V16 -> V17
# helper. It never selects another endpoint, never touches media, and never
# starts an AI workload. The only runtime publication after schema validation
# is a refresh of the restricted compatibility BFF.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$baselineHelper = Join-Path $PSScriptRoot 'capture-private-v18-migration-baseline.ps1'
$acceptanceHelper = Join-Path $PSScriptRoot 'attest-v4-synthetic-phase-ab.ps1'
$migrationPlanRoot = Join-Path $projectRoot '.codex-work\private-real-full\migration-v18'
# The lock is an ignored, local-only coordination artifact.  It is not part of
# the aggregate baseline and it never contains source provenance or media data.
$lockPath = Join-Path $projectRoot '.codex-work\private-real-full\runtime\class-v18-owner-migration.lock'
. (Join-Path $PSScriptRoot 'class-plugin-workflow-lock.ps1')
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

$migrationSourceVersion = 17
$migrationTargetVersion = 18
$migrationRequiredStatus = 'REQUIRED_CURRENT_V17'
$migrationCurrentStatus = 'NOT_REQUIRED_CURRENT_V18'

$target = @{
    endpoint = 'owner'
    piwigo_env = 'infra/private-full/.env.piwigo.owner'
    immich_env = 'infra/private-full/.env.immich.owner'
    http_port = '8190'
    compat_port = '8191'
    validation_action = 'validate-owner'
    runtime_action = 'runtime-owner'
}

function Stop-V18OwnerMigration([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_V18_OWNER_STOP:' + $Code)
}

function Assert-OwnerTarget {
    if ($Endpoint -ne 'owner' -or [string]$target.endpoint -ne 'owner' -or
        [string]$target.http_port -ne '8190' -or [string]$target.compat_port -ne '8191') {
        Stop-V18OwnerMigration 'owner_endpoint_required'
    }
}

function Assert-DeploymentSchemaContract {
    $schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    if (-not (Test-Path -LiteralPath $schemaPath -PathType Leaf)) { Stop-V18OwnerMigration 'schema_source_missing' }
    $source = [IO.File]::ReadAllText($schemaPath)
    $matches = [regex]::Matches($source, 'public\s+const\s+CURRENT_VERSION\s*=\s*([0-9]+)\s*;')
    if ($matches.Count -ne 1 -or [int]$matches[0].Groups[1].Value -ne $migrationTargetVersion -or
        -not $source.Contains("'name' => '0018_photos_app_v4_spotlight_rotation_state'") -or
        -not $source.Contains('migrationPhotosAppV4SpotlightRotationState')) {
        Stop-V18OwnerMigration 'schema_target_contract_mismatch'
    }
}

function Get-WslPath([string]$WindowsPath) {
    $full = [IO.Path]::GetFullPath($WindowsPath)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V18OwnerMigration 'immich_env_missing' }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec wslpath -a $full 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0 -or $lines.Count -ne 1) { Stop-V18OwnerMigration 'immich_env_path_invalid' }
    $path = ([string]$lines[0]).Trim()
    if ($path -notmatch '^/mnt/[a-z]/') { Stop-V18OwnerMigration 'immich_env_path_invalid' }
    return $path
}

$piwigoCompose = @(
    '-d','Ubuntu','--cd',$projectRoot,'--',
    'docker','compose','--env-file',[string]$target.piwigo_env,
    '-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml',
    '-p','class_archive_private_full_v3_piwigo','--profile','ops'
)
$immichEnvWindows = Join-Path $projectRoot ([string]$target.immich_env).Replace('/', '\')
$immichEnvWsl = Get-WslPath $immichEnvWindows
$immichCompose = @(
    '-d','Ubuntu','--cd',$projectRoot,'--',
    'env',('IMMICH_SPIKE_ENV_FILE=' + $immichEnvWsl),
    'docker','compose','--env-file',[string]$target.immich_env,
    '-f','infra/immich-spike/docker-compose.yml','-f','infra/private-full/docker-compose.immich.override.yml',
    '-p','class_archive_private_full_v3_immich',
    '--profile','immich-spike','--profile','immich-ml','--profile','immich-web-compat','--profile','immich-gateway-integration'
)

function Invoke-WslCompose([string[]]$Prefix, [string[]]$Arguments, [string]$Code, [switch]$Capture) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" @($Prefix + $Arguments) 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V18OwnerMigration $Code }
    if ($Capture) { return @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' }) }
}

function Invoke-PiwigoCompose([string[]]$Arguments) {
    Invoke-WslCompose $script:piwigoCompose $Arguments 'piwigo_compose_failed'
}

function Invoke-PiwigoComposeCapture([string[]]$Arguments) {
    return Invoke-WslCompose $script:piwigoCompose $Arguments 'piwigo_compose_failed' -Capture
}

function Invoke-ImmichCompose([string[]]$Arguments) {
    Invoke-WslCompose $script:immichCompose $Arguments 'compat_compose_failed'
}

function Invoke-EndpointLifecycle([string]$LifecycleAction) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $lifecycle $LifecycleAction | Out-Null
    if ($LASTEXITCODE -ne 0) { Stop-V18OwnerMigration 'owner_lifecycle_invalid' }
}

function Assert-OwnerLoopbackEndpoints {
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    if (-not (Test-Path -LiteralPath $curl -PathType Leaf)) { Stop-V18OwnerMigration 'owner_loopback_curl_missing' }
    foreach ($uri in @('http://127.0.0.1:8190', 'http://127.0.0.1:8191')) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            # Curl is used deliberately rather than Invoke-WebRequest here.
            # Piwigo's unauthenticated root can produce a redirect whose
            # representation differs across Windows PowerShell and PowerShell
            # 7; this is a bounded local status probe, not an authorization
            # decision.  Any malformed marker or transport error remains 0.
            $lines = @(& $curl --noproxy '*' --silent --show-error --max-time 15 --output NUL --write-out 'CLASS_ARCHIVE_STATUS:%{http_code}' $uri 2>&1)
            $exitCode = $LASTEXITCODE
            $marker = [regex]::Match(([string]::Join("`n", @($lines | ForEach-Object { [string]$_ }))), 'CLASS_ARCHIVE_STATUS:(?<status>\d{3})\z')
            $status = if ($exitCode -eq 0 -and $marker.Success) { [int]$marker.Groups['status'].Value } else { 0 }
        }
        catch {
            $status = 0
        }
        finally { $ErrorActionPreference = $previous }
        if ($status -notin @(200,301,302,303)) { Stop-V18OwnerMigration 'owner_loopback_endpoint_unhealthy' }
    }
}

function Assert-OwnerRuntimeProof {
    # `validate-owner` proves compose/config identity only.  A state-changing
    # V18 operation must additionally inspect the *running* owner containers,
    # volumes, loopback bindings and BFF health before it obtains a workflow
    # lock or publishes maintenance mode.
    Invoke-EndpointLifecycle ([string]$target.runtime_action)
    Assert-OwnerLoopbackEndpoints
}

function Wait-Maintenance {
    foreach ($attempt in 1..60) {
        $previous = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            $lines = Invoke-PiwigoComposeCapture @('exec','-T','piwigo','curl','--silent','--show-error','--write-out','CLASS_ARCHIVE_STATUS:%{http_code}','http://127.0.0.1/')
        }
        catch { $lines = @() }
        finally { $ErrorActionPreference = $previous }
        if ($lines.Count -eq 2 -and $lines[0] -eq 'Class Archive maintenance mode.' -and $lines[1] -eq 'CLASS_ARCHIVE_STATUS:503') { return }
        Start-Sleep -Seconds 1
    }
    Stop-V18OwnerMigration 'maintenance_not_ready'
}

function Assert-PiwigoStoppedForSnapshot {
    $container = 'class_archive_private_full_v3_piwigo-piwigo-1'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --exec docker inspect --format '{{.State.Running}}|{{.State.Status}}' $container 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0 -or $lines.Count -ne 1 -or ([string]$lines[0]).Trim() -ne 'false|exited') { Stop-V18OwnerMigration 'writer_not_stopped' }
}

function Get-PreMigrationSnapshotRequirement {
    $lines = Invoke-PiwigoComposeCapture @(
        'run','--rm',
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=' + $migrationSourceVersion),
        '-e',('CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=' + $migrationTargetVersion),
        '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=probe',
        'pre-migration-db-backup'
    )
    $required = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationRequiredStatus + ' schema_current=17 schema_from=17 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED'
    $current = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationCurrentStatus + ' schema_current=18 schema_from=18 schema_to=18 scope=NONE media=NOT_INCLUDED'
    $records = @($lines | Where-Object { $_ -eq $required -or $_ -eq $current })
    if ($records.Count -ne 1) { Stop-V18OwnerMigration 'schema_probe_invalid' }
    return [string]$records[0]
}

function Assert-SourceSchema17 {
    $record = Get-PreMigrationSnapshotRequirement
    if ($record -ne ('PRE_MIGRATION_DB_SNAPSHOT=' + $migrationRequiredStatus + ' schema_current=17 schema_from=17 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED')) {
        Stop-V18OwnerMigration 'source_schema_not_exact_v17'
    }
}

function Assert-TargetSchema18 {
    $record = Get-PreMigrationSnapshotRequirement
    if ($record -ne ('PRE_MIGRATION_DB_SNAPSHOT=' + $migrationCurrentStatus + ' schema_current=18 schema_from=18 schema_to=18 scope=NONE media=NOT_INCLUDED')) {
        Stop-V18OwnerMigration 'target_schema_not_exact_v18'
    }
}

function Get-OwnerSchemaState {
    $record = Get-PreMigrationSnapshotRequirement
    $required = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationRequiredStatus + ' schema_current=17 schema_from=17 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED'
    $current = 'PRE_MIGRATION_DB_SNAPSHOT=' + $migrationCurrentStatus + ' schema_current=18 schema_from=18 schema_to=18 scope=NONE media=NOT_INCLUDED'
    if ($record -eq $required) { return 'V17' }
    if ($record -eq $current) { return 'V18' }
    Stop-V18OwnerMigration 'schema_probe_invalid'
}

function Capture-PreMigrationCountBaseline {
    # Aggregate-only evidence: source_records, canonical_photos,
    # album_relationships, comments, replies, AI jobs and related persisted
    # projection counts. The helper rejects paths, identifiers and media data.
    $requiredDomains = @('source_records','canonical_photos','album_relationships','comments','replies','ai_jobs_total','ai_jobs_complete','ai_jobs_open')
    if (-not (Test-Path -LiteralPath $baselineHelper -PathType Leaf)) { Stop-V18OwnerMigration 'baseline_helper_missing' }
    $schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    $sourceDigest = (Get-FileHash -LiteralPath $schemaPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($sourceDigest -notmatch '^[a-f0-9]{64}$' -or $requiredDomains.Count -ne 8) { Stop-V18OwnerMigration 'baseline_contract_invalid' }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $baselineHelper -Action capture -Endpoint owner -ExpectedSchema 17 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V18OwnerMigration 'baseline_capture_failed' }
    $records = @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object {
        $_ -match '^PRIVATE_V18_NUMERIC_BASELINE=PASS action=capture endpoint=owner ports=8190_8191 schema=17 baseline=owner-v17-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json sha256=[a-f0-9]{64} privacy=COUNTS_AND_OPAQUE_HASHES_ONLY media=NOT_MOUNTED$'
    })
    if ($records.Count -ne 1) { Stop-V18OwnerMigration 'baseline_capture_evidence_invalid' }
    $match = [regex]::Match($records[0], 'baseline=(owner-v17-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json) sha256=([a-f0-9]{64})')
    if (-not $match.Success) { Stop-V18OwnerMigration 'baseline_capture_evidence_invalid' }
    return @{ Name = $match.Groups[1].Value; Sha256 = $match.Groups[2].Value }
}

function Assert-BaselineReference([hashtable]$Baseline) {
    if ($null -eq $Baseline -or [string]$Baseline.Name -notmatch '^owner-v17-to-v18-baseline-[0-9]{8}T[0-9]{6}Z\.json$' -or
        [string]$Baseline.Sha256 -notmatch '^[a-f0-9]{64}$') {
        Stop-V18OwnerMigration 'baseline_reference_invalid'
    }
}

function Assert-SourceBaselineUnchanged([hashtable]$Baseline) {
    Assert-BaselineReference $Baseline
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $baselineHelper -Action verify-source -Endpoint owner -ExpectedSchema 17 -BaselineName ([string]$Baseline.Name) -ExpectedSha256 ([string]$Baseline.Sha256) 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V18OwnerMigration 'source_baseline_validation_failed' }
    $expected = 'PRIVATE_V18_NUMERIC_BASELINE=PASS action=verify-source endpoint=owner ports=8190_8191 schema=17 baseline=' + [string]$Baseline.Name + ' sha256=' + [string]$Baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED media=NOT_MOUNTED'
    if (@($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -eq $expected }).Count -ne 1) {
        Stop-V18OwnerMigration 'source_baseline_evidence_invalid'
    }
}

function Compare-PostMigrationCountBaseline([hashtable]$Baseline) {
    Assert-BaselineReference $Baseline
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $baselineHelper -Action compare -Endpoint owner -ExpectedSchema 18 -BaselineName ([string]$Baseline.Name) -ExpectedSha256 ([string]$Baseline.Sha256) 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V18OwnerMigration 'post_migration_count_validation_failed' }
    $expected = 'PRIVATE_V18_NUMERIC_BASELINE=PASS action=compare endpoint=owner ports=8190_8191 schema=18 baseline=' + [string]$Baseline.Name + ' sha256=' + [string]$Baseline.Sha256 + ' records=PRESERVED semantics=PRESERVED rotation=IDLE_OR_OPERATIONAL media=NOT_MOUNTED'
    if (@($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -eq $expected }).Count -ne 1) {
        Stop-V18OwnerMigration 'post_migration_count_evidence_invalid'
    }
}

function Create-PreMigrationSnapshot {
    # The pre-migration-db-backup service is DB_ONLY and explicitly reports
    # media=NOT_INCLUDED. It has no application or photo-volume mount. The
    # writer stop is deliberately paired with a maintenance-mode recreate even
    # if snapshot creation or evidence validation fails; an unsuccessful backup
    # must never strand the Owner listener offline.
    $restoreMaintenanceWriter = $false
    try {
        Invoke-PiwigoCompose @('stop','piwigo')
        $restoreMaintenanceWriter = $true
        Assert-PiwigoStoppedForSnapshot
        $lines = Invoke-PiwigoComposeCapture @(
            'run','--rm',
            '-e',('CLASS_ARCHIVE_PRE_MIGRATION_FROM_VERSION=' + $migrationSourceVersion),
            '-e',('CLASS_ARCHIVE_PRE_MIGRATION_TO_VERSION=' + $migrationTargetVersion),
            '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_MODE=snapshot',
            '-e','CLASS_ARCHIVE_PRE_MIGRATION_SNAPSHOT_CONFIRM=true',
            'pre-migration-db-backup'
        )
        $pattern = '^PRE_MIGRATION_DB_SNAPSHOT=PASS bundle=(pre-migration-db-v17-to-v18-[0-9]{8}T[0-9]{6}Z) schema_from=17 schema_to=18 scope=DB_ONLY media=NOT_INCLUDED$'
        $records = @($lines | Where-Object { $_ -match $pattern })
        if ($records.Count -ne 1) { Stop-V18OwnerMigration 'pre_migration_snapshot_evidence_invalid' }
        return [regex]::Match($records[0], 'bundle=(pre-migration-db-v17-to-v18-[0-9]{8}T[0-9]{6}Z)').Groups[1].Value
    }
    finally {
        if ($restoreMaintenanceWriter) { RecreatePiwigoUnderMaintenance }
    }
}

function Get-PreMigrationSnapshotBinding([string]$Bundle) {
    if ($Bundle -notmatch '^pre-migration-db-v17-to-v18-[0-9]{8}T[0-9]{6}Z$') { Stop-V18OwnerMigration 'snapshot_name_invalid' }
    # The service mount contains only the database-only backup volume and the
    # tracked snapshot producer. It never mounts Piwigo media. Verify every
    # archived file before exposing only non-sensitive hashes to this runner.
    $verifyScript = @'
set -eu
bundle="$1"
case "$bundle" in pre-migration-db-v17-to-v18-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]T[0-9][0-9][0-9][0-9][0-9][0-9]Z) ;; *) exit 71 ;; esac
directory="/backup/$bundle"
[ -d "$directory" ] && [ ! -L "$directory" ] || exit 72
cd "$directory"
[ -f COMPLETE ] && [ ! -L COMPLETE ] && [ -f MANIFEST.json ] && [ ! -L MANIFEST.json ] && [ -f SHA256SUMS ] && [ ! -L SHA256SUMS ] && [ -f database.sql.gz ] && [ ! -L database.sql.gz ] || exit 73
sha256sum -c SHA256SUMS >/dev/null
grep -F '"format":1,"scope":"DB_ONLY_PRE_MIGRATION_ROLLBACK"' MANIFEST.json >/dev/null
grep -F '"schema_current":17,"schema_from":17,"schema_to":18' MANIFEST.json >/dev/null
grep -F '"media":"NOT_INCLUDED"' MANIFEST.json >/dev/null
manifest_sha256=$(sha256sum MANIFEST.json | awk '{print $1}')
dump_sha256=$(sha256sum database.sql.gz | awk '{print $1}')
dump_bytes=$(wc -c < database.sql.gz | tr -d ' ')
case "$manifest_sha256:$dump_sha256:$dump_bytes" in *[!0-9a-f:]*|:*|*::*) exit 74 ;; esac
grep -F "\"dump_sha256\":\"$dump_sha256\"" MANIFEST.json >/dev/null
grep -F "\"dump_bytes\":$dump_bytes" MANIFEST.json >/dev/null
printf 'PRIVATE_V18_SNAPSHOT_BINDING=PASS bundle=%s manifest_sha256=%s dump_sha256=%s dump_bytes=%s scope=DB_ONLY media=NOT_INCLUDED\n' "$bundle" "$manifest_sha256" "$dump_sha256" "$dump_bytes"
'@
    $lines = Invoke-PiwigoComposeCapture @('run','--rm','--entrypoint','/bin/sh','pre-migration-db-backup','-eu','-c',$verifyScript,'snapshot-binding',$Bundle)
    $pattern = '^PRIVATE_V18_SNAPSHOT_BINDING=PASS bundle=' + [regex]::Escape($Bundle) + ' manifest_sha256=([a-f0-9]{64}) dump_sha256=([a-f0-9]{64}) dump_bytes=([1-9][0-9]*) scope=DB_ONLY media=NOT_INCLUDED$'
    $records = @($lines | Where-Object { $_ -match $pattern })
    if ($records.Count -ne 1) { Stop-V18OwnerMigration 'snapshot_binding_evidence_invalid' }
    $match = [regex]::Match($records[0], $pattern)
    if (-not $match.Success) { Stop-V18OwnerMigration 'snapshot_binding_evidence_invalid' }
    return @{ Name = $Bundle; ManifestSha256 = $match.Groups[1].Value; DumpSha256 = $match.Groups[2].Value; DumpBytes = $match.Groups[3].Value }
}

function Assert-SnapshotReference([hashtable]$Snapshot) {
    if ($null -eq $Snapshot -or [string]$Snapshot.Name -notmatch '^pre-migration-db-v17-to-v18-[0-9]{8}T[0-9]{6}Z$' -or
        [string]$Snapshot.ManifestSha256 -notmatch '^[a-f0-9]{64}$' -or [string]$Snapshot.DumpSha256 -notmatch '^[a-f0-9]{64}$' -or
        [string]$Snapshot.DumpBytes -notmatch '^[1-9][0-9]*$') {
        Stop-V18OwnerMigration 'snapshot_reference_invalid'
    }
}

function Assert-SnapshotBinding([hashtable]$Snapshot) {
    Assert-SnapshotReference $Snapshot
    $actual = Get-PreMigrationSnapshotBinding ([string]$Snapshot.Name)
    foreach ($name in @('ManifestSha256','DumpSha256','DumpBytes')) {
        if (-not [string]::Equals([string]$actual[$name], [string]$Snapshot[$name], [StringComparison]::Ordinal)) {
            Stop-V18OwnerMigration 'snapshot_binding_mismatch'
        }
    }
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { Stop-V18OwnerMigration 'plan_path_outside_checkout' }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredMigrationPlanDirectory {
    [void][IO.Directory]::CreateDirectory($migrationPlanRoot)
    $item = Get-Item -LiteralPath $migrationPlanRoot -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V18OwnerMigration 'plan_directory_untrusted' }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -ne 0) { Stop-V18OwnerMigration 'plan_directory_not_ignored' }
    return $item.FullName
}

function Get-CurrentHead {
    $lines = @(& git -C $projectRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 1 -or ([string]$lines[0]).Trim() -notmatch '^[a-f0-9]{40}$') { Stop-V18OwnerMigration 'git_head_invalid' }
    return ([string]$lines[0]).Trim()
}

function Assert-CleanMigrationCheckout {
    # A plan binds a commit and source digests. It must not be applied with
    # staged, unstaged, or untracked tracked-state changes that the commit hash
    # cannot represent. Ignored local evidence remains allowed by design.
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0 -or $lines.Count -ne 0) { Stop-V18OwnerMigration 'migration_checkout_not_clean' }
}

function Get-MigrationPlanPath([string]$Name) {
    if ($Name -notmatch '^owner-v17-to-v18-plan-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-V18OwnerMigration 'migration_plan_name_invalid' }
    $root = Assert-IgnoredMigrationPlanDirectory
    return (Join-Path $root $Name)
}

function Invoke-V4AcceptanceGate([string]$Name) {
    if ([string]::IsNullOrWhiteSpace($Name) -or -not (Test-Path -LiteralPath $acceptanceHelper -PathType Leaf)) { Stop-V18OwnerMigration 'v4_acceptance_gate_missing' }
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $acceptanceHelper -Action Verify -GateName $Name 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V18OwnerMigration 'v4_acceptance_gate_invalid' }
    $pattern = '^V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Verify gate=(v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json) sha256=([a-f0-9]{64}) scope=SYNTHETIC_8091 browser=GOOGLE_CHROME_STABLE media=MEDIAGUARD_REGRESSION$'
    $records = @($lines | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -match $pattern })
    if ($records.Count -ne 1) { Stop-V18OwnerMigration 'v4_acceptance_gate_evidence_invalid' }
    $match = [regex]::Match($records[0], $pattern)
    if (-not $match.Success -or -not [string]::Equals($match.Groups[1].Value, $Name, [StringComparison]::Ordinal)) { Stop-V18OwnerMigration 'v4_acceptance_gate_evidence_invalid' }
    return @{ Name = $match.Groups[1].Value; Sha256 = $match.Groups[2].Value }
}

function Write-MigrationPlan([hashtable]$Baseline, [hashtable]$Snapshot, [hashtable]$Acceptance) {
    Assert-BaselineReference $Baseline
    Assert-SnapshotReference $Snapshot
    if ($null -eq $Acceptance -or [string]$Acceptance.Name -notmatch '^v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json$' -or [string]$Acceptance.Sha256 -notmatch '^[a-f0-9]{64}$') {
        Stop-V18OwnerMigration 'v4_acceptance_gate_reference_invalid'
    }
    $root = Assert-IgnoredMigrationPlanDirectory
    $name = 'owner-v17-to-v18-plan-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '.json'
    $path = Join-Path $root $name
    if (Test-Path -LiteralPath $path) { Stop-V18OwnerMigration 'migration_plan_already_exists' }
    $schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    $record = [ordered]@{
        format = 1
        scope = 'OWNER_V17_TO_V18_MIGRATION_PLAN'
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        source_head = Get-CurrentHead
        schema_source_sha256 = (Get-FileHash -LiteralPath $schemaPath -Algorithm SHA256).Hash.ToLowerInvariant()
        baseline = [ordered]@{ name = [string]$Baseline.Name; sha256 = [string]$Baseline.Sha256 }
        snapshot = [ordered]@{ name = [string]$Snapshot.Name; manifest_sha256 = [string]$Snapshot.ManifestSha256; dump_sha256 = [string]$Snapshot.DumpSha256; dump_bytes = [string]$Snapshot.DumpBytes }
        v4_acceptance = [ordered]@{ gate = [string]$Acceptance.Name; sha256 = [string]$Acceptance.Sha256 }
        privacy = 'OPAQUE_LEAF_NAMES_AND_HASHES_ONLY_NO_PATHS_IDS_FILENAMES_MEDIA_OR_SECRETS'
    }
    [IO.File]::WriteAllText($path, (($record | ConvertTo-Json -Depth 6 -Compress) + [Environment]::NewLine), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    return @{ Name = $name; Sha256 = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() }
}

function Read-MigrationPlan([string]$Name, [string]$ExpectedAcceptanceGate) {
    $path = Get-MigrationPlanPath $Name
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-V18OwnerMigration 'migration_plan_missing' }
    $item = Get-Item -LiteralPath $path -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V18OwnerMigration 'migration_plan_untrusted' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    try { $record = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-V18OwnerMigration 'migration_plan_json_invalid' }
    if ([int]$record.format -ne 1 -or [string]$record.scope -ne 'OWNER_V17_TO_V18_MIGRATION_PLAN' -or
        [string]$record.source_head -ne (Get-CurrentHead) -or [string]$record.schema_source_sha256 -notmatch '^[a-f0-9]{64}$') {
        Stop-V18OwnerMigration 'migration_plan_contract_invalid'
    }
    $schemaPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Schema.php'
    if (-not [string]::Equals([string]$record.schema_source_sha256, (Get-FileHash -LiteralPath $schemaPath -Algorithm SHA256).Hash.ToLowerInvariant(), [StringComparison]::Ordinal)) {
        Stop-V18OwnerMigration 'migration_plan_schema_source_stale'
    }
    $baseline = @{ Name = [string]$record.baseline.name; Sha256 = [string]$record.baseline.sha256 }
    $snapshot = @{ Name = [string]$record.snapshot.name; ManifestSha256 = [string]$record.snapshot.manifest_sha256; DumpSha256 = [string]$record.snapshot.dump_sha256; DumpBytes = [string]$record.snapshot.dump_bytes }
    Assert-BaselineReference $baseline
    Assert-SnapshotReference $snapshot
    $gateName = [string]$record.v4_acceptance.gate
    if ([string]::IsNullOrWhiteSpace($ExpectedAcceptanceGate) -or -not [string]::Equals($gateName, $ExpectedAcceptanceGate, [StringComparison]::Ordinal) -or [string]$record.v4_acceptance.sha256 -notmatch '^[a-f0-9]{64}$') {
        Stop-V18OwnerMigration 'migration_plan_acceptance_gate_mismatch'
    }
    $acceptance = Invoke-V4AcceptanceGate $ExpectedAcceptanceGate
    if (-not [string]::Equals([string]$acceptance.Sha256, [string]$record.v4_acceptance.sha256, [StringComparison]::Ordinal)) { Stop-V18OwnerMigration 'migration_plan_acceptance_gate_stale' }
    Assert-SnapshotBinding $snapshot
    return @{ Name = $Name; Sha256 = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant(); Baseline = $baseline; Snapshot = $snapshot; Acceptance = $acceptance }
}

function RecreatePiwigoUnderMaintenance {
    Invoke-PiwigoCompose @('up','-d','--force-recreate','--no-deps','piwigo')
    Wait-Maintenance
    Invoke-PiwigoCompose @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare')
}

function InstallAndMigrateV18 {
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php')
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php')
    Invoke-PiwigoCompose @('exec','-T','--user','root','piwigo','/bin/ash','/workspace/infra/scripts/restore-piwigo-user-script.sh')
    RecreatePiwigoUnderMaintenance
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--verify-runtime')
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-locked-piwigo-extensions.php','--verify-only')
}

function RebuildReadProjectionAndRefreshCompat {
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json')
    # Refresh only the constrained BFF route process. No other Immich service
    # is named or restarted by this operation.
    Invoke-ImmichCompose @('up','-d','--wait','--wait-timeout','60','--force-recreate','immich-web-compat')
}

function Enter-Maintenance {
    Invoke-PiwigoCompose @('exec','-T','--user','root','piwigo','php','/workspace/infra/scripts/prepare-class-archive-maintenance.php','--prepare')
    Wait-Maintenance
}

function Finalize-Maintenance {
    Invoke-PiwigoCompose @('exec','-T','--user','nginx','piwigo','php','/workspace/infra/scripts/install-class-archive-plugins.php','--finalize-maintenance')
}

$lock = $null
try {
    Assert-OwnerTarget
    Assert-DeploymentSchemaContract
    if ($Action -in @('Snapshot', 'Migrate')) {
        if (-not $ConfirmOwnerV18Migration) {
            Stop-V18OwnerMigration 'owner_confirmation_required'
        }
    }

    if ($Action -eq 'Probe') {
        Assert-OwnerRuntimeProof
        $state = Get-OwnerSchemaState
        if ($state -eq 'V17') {
            Write-Output 'PRIVATE_V18_OWNER_MIGRATION=PASS action=Probe endpoint=owner ports=8190_8191 schema_from=17 schema_to=18 state=READY media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED manual_rollback_required'
        }
        else {
            Write-Output 'PRIVATE_V18_OWNER_MIGRATION=PASS action=Probe endpoint=owner ports=8190_8191 schema_from=17 schema_to=18 state=ALREADY_CURRENT_V18 media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED manual_rollback_required'
        }
        return
    }

    if ($Action -eq 'Validate') {
        if ([string]::IsNullOrWhiteSpace($MigrationPlanName) -or [string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V18OwnerMigration 'migration_plan_or_v4_gate_required' }
        Assert-CleanMigrationCheckout
        Assert-OwnerRuntimeProof
        $plan = Read-MigrationPlan $MigrationPlanName $V4AcceptanceGateName
        Assert-TargetSchema18
        Compare-PostMigrationCountBaseline $plan.Baseline
        Write-Output ('PRIVATE_V18_OWNER_MIGRATION=PASS action=Validate endpoint=owner ports=8190_8191 schema_from=17 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $plan.Snapshot.Name + ' counts=PRESERVED semantics=PRESERVED media=UNTOUCHED ai=UNCHANGED maintenance=NOT_ENTERED manual_rollback_required')
        return
    }

    if ($Action -eq 'Snapshot') {
        if ([string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V18OwnerMigration 'v4_acceptance_gate_required' }
        Assert-CleanMigrationCheckout
        $acceptance = Invoke-V4AcceptanceGate $V4AcceptanceGateName
        Assert-OwnerRuntimeProof
        if ((Get-OwnerSchemaState) -ne 'V17') { Stop-V18OwnerMigration 'snapshot_requires_source_schema_v17' }
        # All source, runtime and Synthetic Phase A/B evidence has now been
        # checked without a lock or maintenance mutation. Only a V17 source
        # reaches this state-changing section.
        $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
        Enter-Maintenance
        Assert-SourceSchema17
        $baseline = Capture-PreMigrationCountBaseline
        $snapshotName = Create-PreMigrationSnapshot
        $snapshot = Get-PreMigrationSnapshotBinding $snapshotName
        $plan = Write-MigrationPlan $baseline $snapshot $acceptance
        Finalize-Maintenance
        Invoke-EndpointLifecycle ([string]$target.runtime_action)
        Write-Output ('PRIVATE_V18_OWNER_MIGRATION=PASS action=Snapshot endpoint=owner ports=8190_8191 schema_from=17 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $snapshot.Name + ' snapshot_manifest_sha256=' + $snapshot.ManifestSha256 + ' baseline=' + $baseline.Name + ' baseline_sha256=' + $baseline.Sha256 + ' scope=DB_ONLY media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
        return
    }

    # Migrate consumes a hash-bound Snapshot plan. It never creates a second
    # rollback point. This makes the Phase C snapshot the exact evidence used
    # by Phase D and fails before maintenance when either record has drifted.
    if ([string]::IsNullOrWhiteSpace($MigrationPlanName) -or [string]::IsNullOrWhiteSpace($V4AcceptanceGateName)) { Stop-V18OwnerMigration 'migration_plan_or_v4_gate_required' }
    Assert-CleanMigrationCheckout
    Assert-OwnerRuntimeProof
    $schemaState = Get-OwnerSchemaState
    $plan = Read-MigrationPlan $MigrationPlanName $V4AcceptanceGateName

    if ($schemaState -eq 'V18') {
        # A retry after a successful migration is a validation-only replay. It
        # must not lock, re-enter maintenance, reinstall extensions, or refresh
        # projections. The retained plan proves exactly what was migrated.
        Assert-TargetSchema18
        Compare-PostMigrationCountBaseline $plan.Baseline
        Write-Output ('PRIVATE_V18_OWNER_MIGRATION=PASS action=Migrate endpoint=owner ports=8190_8191 schema_from=18 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' idempotent_replay=PASS maintenance=NOT_ENTERED projection=UNCHANGED bff=UNCHANGED media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
        return
    }

    Assert-SourceBaselineUnchanged $plan.Baseline
    Assert-SnapshotBinding $plan.Snapshot
    # The V17 preflight has succeeded before the workflow lock. Re-run the
    # source baseline after maintenance begins to close the small interval
    # between Snapshot validation and the byte-changing installation step.
    $lock = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
    Enter-Maintenance
    Assert-SourceSchema17
    Assert-SourceBaselineUnchanged $plan.Baseline
    Assert-SnapshotBinding $plan.Snapshot
    InstallAndMigrateV18
    Assert-TargetSchema18
    RebuildReadProjectionAndRefreshCompat
    Compare-PostMigrationCountBaseline $plan.Baseline
    Finalize-Maintenance
    Invoke-EndpointLifecycle ([string]$target.runtime_action)
    Write-Output ('PRIVATE_V18_OWNER_MIGRATION=PASS action=Migrate endpoint=owner ports=8190_8191 schema_from=17 schema_to=18 plan=' + $plan.Name + ' plan_sha256=' + $plan.Sha256 + ' snapshot=' + $plan.Snapshot.Name + ' baseline=' + $plan.Baseline.Name + ' projection=REBUILT bff=COMPAT_ONLY counts=PRESERVED semantics=PRESERVED media=UNTOUCHED ai=UNCHANGED manual_rollback_required')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^PRIVATE_V18_OWNER_STOP:([a-z0-9_]{1,96})$') { $Matches[1] } else { 'private_v18_owner_migration_failed' }
    # Never restore a V17 database below V18 plugin bytes from a catch/finally
    # block. Keep the maintenance boundary for an explicitly reviewed manual
    # recovery procedure documented in docs/private-v18-owner-migration.md.
    # Never echo an arbitrary exception message here: the workflow reads
    # private runtime configuration, and a provider error can include a host
    # path or an environment value.  Keep the public protocol diagnosable
    # without weakening that boundary by emitting only a bounded exception
    # type and source line for otherwise-unclassified failures.
    if ($code -eq 'private_v18_owner_migration_failed') {
        $exceptionType = $_.Exception.GetType().Name
        if ($exceptionType -notmatch '^[A-Za-z0-9]{1,64}$') { $exceptionType = 'Exception' }
        $line = [int]$_.InvocationInfo.ScriptLineNumber
        if ($line -lt 1 -or $line -gt 99999) { $line = 0 }
        $code = ('unexpected_' + $exceptionType + '_line_' + $line)
    }
    Write-Output "PRIVATE_V18_OWNER_MIGRATION=FAIL action=$Action endpoint=$Endpoint code=$code manual_rollback_required"
    exit 2
}
finally {
    Exit-ClassArchivePluginWorkflowLock -Handle $lock
}
