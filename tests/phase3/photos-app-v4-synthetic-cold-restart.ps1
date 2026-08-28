[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [switch]$ConfirmSyntheticMutation,

    [Parameter(Mandatory = $true)]
    [switch]$ConfirmServiceRestart
)

# Real V4 Phase-B restart evidence for the public synthetic service only.
# It first reuses the existing mutation/rollback read-projection runtime proof,
# then takes a V18 persistence snapshot, restarts only the already-running
# public Piwigo/BFF containers, and compares the post-restart snapshot before
# minting the narrow attester record. It never starts a service, reaches a
# private runtime, or accesses a private-library volume.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$script:Assertions = 0
$script:Stage = 'startup'

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:Assertions++
}

function Invoke-WslDocker([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $script:ProjectRoot --exec docker @Arguments 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) {
        throw ('v4_synthetic_cold_restart_docker_failed_' + $exit)
    }
    return @($lines | ForEach-Object { [string]$_ })
}

function Get-PublicContainer([string]$Project, [string]$Service, [string]$ExpectedName) {
    $rows = @(Invoke-WslDocker @(
        'ps', '--filter', "label=com.docker.compose.project=$Project",
        '--filter', "label=com.docker.compose.service=$Service",
        '--format', '{{.ID}}|{{.Names}}'
    ) | Where-Object { $_ -match '\A[0-9a-f]{12,64}\|[A-Za-z0-9_.-]+\z' })
    Assert-True ($rows.Count -eq 1) 'v4_synthetic_cold_restart_container_scope_invalid'
    $parts = $rows[0].Split('|', 2)
    Assert-True ($parts[1] -eq $ExpectedName) 'v4_synthetic_cold_restart_container_name_invalid'
    return [ordered]@{ id = $parts[0]; name = $parts[1] }
}

function Wait-PublicContainerHealthy([string]$ContainerId, [string]$Code) {
    $deadline = [DateTime]::UtcNow.AddSeconds(120)
    do {
        $state = @(Invoke-WslDocker @('inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', $ContainerId) | Select-Object -Last 1)
        if ($state.Count -eq 1 -and $state[0].Trim() -eq 'healthy') { return }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw $Code
}

function Get-JsonOutput([string[]]$Arguments, [string]$Code) {
    $lines = @(Invoke-WslDocker $Arguments)
    $json = @($lines | Where-Object { $_ -match '^\{.*\}$' })
    if ($json.Count -ne 1) { throw $Code }
    try { return ($json[0] | ConvertFrom-Json -ErrorAction Stop) }
    catch { throw $Code }
}

function Assert-SyntheticBaseline([string]$Code) {
    $state = Get-JsonOutput @(
        'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE=1',
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-upload-lifecycle-fixture.php', 'baseline'
    ) 'v4_synthetic_cold_restart_baseline_fixture_failed'
    Assert-True (
        [int]$state.images -eq 72 -and [int]$state.active_canonical -eq 72 -and
        [int]$state.physical_originals -eq 72 -and [int]$state.multi_album_images -eq 8
    ) $Code
}

function Get-V4ColdRestartSnapshot {
    return Get-JsonOutput @(
        'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_V4_SYNTHETIC_COLD_RESTART=1',
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-synthetic-cold-restart-snapshot.php'
    ) 'v4_synthetic_cold_restart_snapshot_missing'
}

function Assert-V4ColdRestartSnapshot([object]$Snapshot, [string]$Code) {
    # Report only a narrow invariant label on failure. The snapshot itself
    # intentionally contains aggregate counts/digests only, but this makes a
    # synthetic restart failure actionable without exposing any browser or
    # account state in the test transcript.
    $checks = [ordered]@{
        result = $Snapshot.result -eq 'PASS'
        schema = [int]$Snapshot.schema_version -eq 18
        baseline_images = [int]$Snapshot.baseline.images -eq 72
        baseline_canonical = [int]$Snapshot.baseline.active_canonical -eq 72
        baseline_originals = [int]$Snapshot.baseline.physical_originals -eq 72
        baseline_multi_album = [int]$Snapshot.baseline.multi_album_images -eq 8
        projection_count = [int]$Snapshot.projections.count -eq 6
        projection_digest = [string]$Snapshot.projections.digest -match '^[a-f0-9]{64}$'
        snapshot_pointer_count = [int]$Snapshot.collection_snapshots.pointer_count -eq 8
        snapshot_pointer_digest = [string]$Snapshot.collection_snapshots.pointer_digest -match '^[a-f0-9]{64}$'
        snapshot_items = [int]$Snapshot.collection_snapshots.item_count -ge 1
        snapshot_maintenance_count = [int]$Snapshot.collection_snapshots.maintenance_count -eq 2
        snapshot_maintenance_digest = [string]$Snapshot.collection_snapshots.maintenance_digest -match '^[a-f0-9]{64}$'
        spotlight_count = [int]$Snapshot.spotlight_rotation.count -eq 2
        spotlight_digest = [string]$Snapshot.spotlight_rotation.digest -match '^[a-f0-9]{64}$'
        ai_asset_count = [int]$Snapshot.ai.asset_index_count -ge 0
        ai_asset_digest = [string]$Snapshot.ai.asset_index_digest -match '^[a-f0-9]{64}$'
        ai_job_count = [int]$Snapshot.ai.job_count -ge 0
        ai_open_jobs = [int]$Snapshot.ai.open_job_count -eq 0
        ai_job_digest = [string]$Snapshot.ai.job_digest -match '^[a-f0-9]{64}$'
    }
    $failed = @($checks.Keys | Where-Object { $checks[$_] -ne $true })
    Assert-True ($failed.Count -eq 0) ($Code + '_' + $(if ($failed.Count -gt 0) { $failed[0] } else { 'unknown' }))
}

function Assert-V4ColdRestartStable([object]$Before, [object]$After) {
    $beforeJson = $Before | ConvertTo-Json -Depth 12 -Compress
    $afterJson = $After | ConvertTo-Json -Depth 12 -Compress
    Assert-True ($beforeJson -ceq $afterJson) 'v4_synthetic_cold_restart_state_changed'
}

function Invoke-ExistingProjectionRuntime {
    $runner = Join-Path $script:ProjectRoot 'tests\phase3\read-projection-runtime.ps1'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner `
            -ConfirmSyntheticMutation -ConfirmServiceRestart 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    $lines = @($output | ForEach-Object { [string]$_ })
    $pass = @($lines | Where-Object { $_ -match '^READ_PROJECTION_RUNTIME=PASS assertions=[0-9]+ restart_ms=[0-9]+ first_http_ms=[0-9]+$' })
    if ($exit -ne 0 -or $pass.Count -ne 1 -or @($lines | Where-Object { $_ -match '^READ_PROJECTION_RUNTIME=FAIL\b' }).Count -ne 0) {
        throw 'v4_synthetic_cold_restart_projection_runtime_failed'
    }
    Assert-True $true 'v4_synthetic_cold_restart_projection_runtime_pass'
}

function Invoke-JsonGet([string]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    return Invoke-RestMethod -Uri $Uri -Method Get -WebSession $Session -Headers @{ Accept = 'application/json' } -TimeoutSec 30
}

$lease = $null
$phaseAMutationLease = $null
$completed = $false
$failureCode = $null
try {
    Assert-True $ConfirmSyntheticMutation.IsPresent 'v4_synthetic_cold_restart_mutation_confirmation_required'
    Assert-True $ConfirmServiceRestart.IsPresent 'v4_synthetic_cold_restart_service_confirmation_required'
    $script:ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    . (Join-Path $script:ProjectRoot 'infra\scripts\v4-synthetic-phase-a-lease.ps1')
    $phaseAMutationLease = Enter-V4SyntheticPhaseAMutationLease -ProjectRoot $script:ProjectRoot -Purpose 'cold-restart'
    Assert-SyntheticBaseline 'v4_synthetic_cold_restart_baseline_before_failed'

    # This performs an existing, independent real write/rollback proof and a
    # first public restart before the V4-only no-recompute restart below.
    $script:Stage = 'read_projection_runtime'
    Invoke-ExistingProjectionRuntime
    Assert-SyntheticBaseline 'v4_synthetic_cold_restart_baseline_after_projection_runtime_failed'

    $script:Stage = 'container_discovery'
    $piwigo = Get-PublicContainer 'class_archive_piwigo' 'piwigo' 'class_archive_piwigo-piwigo-1'
    $compat = Get-PublicContainer 'class-archive-immich-spike' 'immich-web-compat' 'class-archive-immich-spike-immich-web-compat-1'
    $piwigoPorts = @(Invoke-WslDocker @('port', $piwigo.id))
    Assert-True ($piwigoPorts -contains '80/tcp -> 127.0.0.1:8090') 'v4_synthetic_cold_restart_core_not_loopback'
    Assert-True ($piwigoPorts -contains '8081/tcp -> 127.0.0.1:8091') 'v4_synthetic_cold_restart_bff_not_loopback'
    Assert-True (@(Invoke-WslDocker @('port', $compat.id)).Count -eq 0) 'v4_synthetic_cold_restart_bff_host_exposed'
    $mounts = @(Invoke-WslDocker @('inspect', '--format', '{{range .Mounts}}{{println .Name}}{{end}}', $piwigo.id))
    Assert-True (@($mounts | Where-Object { $_ -match 'private[_-]qa' }).Count -eq 0) 'v4_synthetic_cold_restart_private_mount_detected'

    $script:Stage = 'v4_snapshot_before'
    $before = Get-V4ColdRestartSnapshot
    Assert-V4ColdRestartSnapshot $before 'v4_synthetic_cold_restart_snapshot_before_invalid'

    $script:Stage = 'v4_restart'
    [void](Invoke-WslDocker @('restart', $compat.id, $piwigo.id))
    Wait-PublicContainerHealthy $compat.id 'v4_synthetic_cold_restart_compat_unhealthy'
    Wait-PublicContainerHealthy $piwigo.id 'v4_synthetic_cold_restart_piwigo_unhealthy'

    $script:Stage = 'v4_snapshot_after'
    $after = Get-V4ColdRestartSnapshot
    Assert-V4ColdRestartSnapshot $after 'v4_synthetic_cold_restart_snapshot_after_invalid'
    Assert-V4ColdRestartStable $before $after

    $script:Stage = 'immediate_projection_reads'
    . (Join-Path $script:ProjectRoot 'tests\support\system-admin-session.ps1')
    $script:Stage = 'immediate_projection_admin_username'
    $adminUsername = ''
    foreach ($line in [IO.File]::ReadAllLines((Join-Path $script:ProjectRoot '.env.piwigo'))) {
        if ($line.StartsWith('PIWIGO_ADMIN_USERNAME=')) {
            $adminUsername = $line.Substring('PIWIGO_ADMIN_USERNAME='.Length)
            break
        }
    }
    Assert-True ($adminUsername -match '^[A-Za-z0-9_.@+-]{1,100}$') 'v4_synthetic_cold_restart_admin_username_invalid'
    $composeBase = [string[]]@(
        '-d', 'Ubuntu', '--cd', $script:ProjectRoot, '--exec',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
    )
    $script:Stage = 'immediate_projection_admin_session'
    try {
        $lease = New-ClassArchiveSystemAdminSession -BaseUri ([Uri]'http://127.0.0.1:8090/') `
            -ComposeBase $composeBase -AdminUsername $adminUsername
    }
    catch {
        throw 'v4_synthetic_cold_restart_admin_session_unavailable'
    }
    $projectionReads = [ordered]@{
        home = 'http://127.0.0.1:8091/api/class-archive/collections/home'
        pins = 'http://127.0.0.1:8091/api/class-archive/collections/pins'
        suggestions = 'http://127.0.0.1:8091/api/class-archive/search/suggestions?q=%E8%AE%B0%E5%BF%86'
        timeline = 'http://127.0.0.1:8091/api/class-archive/timeline'
    }
    foreach ($name in $projectionReads.Keys) {
        $script:Stage = ('immediate_projection_' + $name + '_request')
        try {
            $payload = Invoke-JsonGet ([string]$projectionReads[$name]) $lease.Session
        }
        catch {
            throw ('v4_synthetic_cold_restart_projection_' + $name + '_request_failed')
        }
        $script:Stage = ('immediate_projection_' + $name + '_validate')
        try {
            $propertyCount = @($payload.PSObject.Properties).Count
        }
        catch {
            throw ('v4_synthetic_cold_restart_projection_' + $name + '_payload_shape_invalid')
        }
        Assert-True ($null -ne $payload -and $propertyCount -ge 1) ('v4_synthetic_cold_restart_projection_' + $name + '_payload_invalid')
    }
    $completed = $true
}
catch {
    $raw = [string]$_.Exception.Message
    $failureCode = if ($raw -match '\A[a-z0-9_]{1,128}\z') { $raw } else { 'v4_synthetic_cold_restart_failed' }
}
finally {
    if ($null -ne $lease) {
        try {
            Remove-ClassArchiveSystemAdminSession -Lease $lease
            $lease = $null
        }
        catch {
            $failureCode = 'v4_synthetic_cold_restart_session_cleanup_failed'
        }
    }
}

if (-not $completed -and $null -eq $failureCode) {
    $failureCode = 'v4_synthetic_cold_restart_incomplete'
}
if ($null -ne $failureCode) {
    try {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
    catch {
        $failureCode = 'v4_synthetic_cold_restart_host_lease_cleanup_failed'
    }
    Write-Error ('V4_SYNTHETIC_COLD_RESTART=FAIL code=' + $failureCode + ' stage=' + $script:Stage)
    exit 1
}

# Re-run the exact baseline check only after finally has revoked the
# short-lived SYSTEM_ADMIN lease. A failed cleanup or any post-cleanup drift
# therefore cannot leave an early successful attester record in the raw log.
try {
    $script:Stage = 'post_cleanup_baseline'
    Assert-SyntheticBaseline 'v4_synthetic_cold_restart_baseline_after_failed'
}
catch {
    $postCleanupFailure = 'v4_synthetic_cold_restart_baseline_after_failed'
    try {
        if ($null -ne $phaseAMutationLease) {
            Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
            $phaseAMutationLease = $null
        }
    }
    catch {
        $postCleanupFailure = 'v4_synthetic_cold_restart_host_lease_cleanup_failed'
    }
    Write-Error ('V4_SYNTHETIC_COLD_RESTART=FAIL code=' + $postCleanupFailure + ' stage=post_cleanup_baseline')
    exit 1
}

try {
    if ($null -ne $phaseAMutationLease) {
        Exit-V4SyntheticPhaseAMutationLease -Lease $phaseAMutationLease
        $phaseAMutationLease = $null
    }
}
catch {
    Write-Error 'V4_SYNTHETIC_COLD_RESTART=FAIL code=v4_synthetic_cold_restart_host_lease_cleanup_failed stage=post_cleanup_lease'
    exit 1
}

# Both records are emitted only after every restart, HTTP read, lease cleanup,
# and exact baseline check has completed. The second is intentionally an exact
# terminal marker consumed by the normalizer/attester.
Write-Output 'V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8'
Write-Output 'V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS'
