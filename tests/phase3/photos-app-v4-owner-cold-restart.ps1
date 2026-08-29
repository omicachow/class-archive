[CmdletBinding()]
param(
    # This is intentionally a two-personality boundary: the script is inert
    # until an operator explicitly acknowledges both the private Owner scope
    # and the restart. It is never part of public CI.
    [Parameter(Mandatory = $true)]
    [switch]$ConfirmOwnerPrivateRestart,

    [Parameter(Mandatory = $true)]
    [switch]$ConfirmServingContainerRestart
)

# Local-only V4 persistence evidence for the already-cut-over private-full
# Owner runtime. It never imports media, rebuilds an AI index, opens a source
# directory, starts a Compose project, or changes a volume. Its only lifecycle
# command is docker restart on an exact, label-bound allowlist of existing
# owner-serving containers. Aggregate snapshots and evidence remain ignored.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$script:Assertions = 0
$script:Stage = 'startup'
$script:ProjectRoot = ''

function Stop-OwnerColdRestart([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_COLD_RESTART_STOP:' + $Code)
}

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { Stop-OwnerColdRestart $Code }
    $script:Assertions++
}

function New-RunId {
    $bytes = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-OwnerDocker([string[]]$Arguments, [string]$Code, [ValidateRange(1,300)][int]$TimeoutSeconds = 120) {
    # Build the fixed launcher prefix as its own array before concatenating the
    # caller arguments. Putting `+ $Arguments` inside the @() literal makes
    # PowerShell invoke op_Addition on String[] at runtime.
    $dockerArguments = @('-d','Ubuntu','--cd',$script:ProjectRoot,'--exec','docker') + $Arguments
    $bounded = Add-ClassArchiveWslTimeout -Arguments $dockerArguments -TimeoutSeconds $TimeoutSeconds
    try {
        $result = Invoke-ClassArchiveBoundedNative -Executable (Join-Path $env:SystemRoot 'System32\wsl.exe') `
            -Arguments $bounded -TimeoutSeconds ($TimeoutSeconds + 15) -WorkingDirectory $script:ProjectRoot
    }
    catch { Stop-OwnerColdRestart ($Code + '_start_failed') }
    if ($result.TimedOut) { Stop-OwnerColdRestart ($Code + '_timeout') }
    if ($null -eq $result.ExitCode -or [int]$result.ExitCode -ne 0) { Stop-OwnerColdRestart $Code }
    return @(([string]$result.Stdout -split "`r?`n") | ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' })
}

function Get-ExactOwnerContainer([hashtable]$Spec) {
    $rows = @(Invoke-OwnerDocker @(
        'ps', '--filter', ('label=com.docker.compose.project=' + $Spec.project),
        '--filter', ('label=com.docker.compose.service=' + $Spec.service),
        '--format', '{{.ID}}|{{.Names}}'
    ) 'owner_container_discovery_failed' 30 | Where-Object { $_ -match '\A[0-9a-f]{12,64}\|[A-Za-z0-9_.-]+\z' })
    Assert-True ($rows.Count -eq 1) 'owner_container_scope_invalid'
    $parts = $rows[0].Split('|', 2)
    Assert-True ($parts[1] -eq $Spec.name) 'owner_container_name_invalid'

    $labels = @(Invoke-OwnerDocker @(
        'inspect','--format','{{index .Config.Labels "com.classarchive.scope"}}|{{index .Config.Labels "com.docker.compose.project"}}|{{index .Config.Labels "com.docker.compose.service"}}',$parts[0]
    ) 'owner_container_label_inspect_failed' 30)
    Assert-True ($labels.Count -eq 1 -and $labels[0] -eq ('private-real-full|' + $Spec.project + '|' + $Spec.service)) 'owner_container_label_invalid'
    return [ordered]@{ id = $parts[0]; name = $parts[1]; service = $Spec.service; project = $Spec.project }
}

function Wait-OwnerContainerReady([hashtable]$Container, [string]$Code) {
    $deadline = [DateTime]::UtcNow.AddSeconds(180)
    do {
        $rows = @(Invoke-OwnerDocker @(
            'inspect','--format','{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}',$Container.id
        ) 'owner_container_state_inspect_failed' 30)
        if ($rows.Count -eq 1 -and $rows[0] -in @('healthy','running')) { return }
        Start-Sleep -Milliseconds 750
    } while ([DateTime]::UtcNow -lt $deadline)
    Stop-OwnerColdRestart $Code
}

function Assert-ExactPiwigoPorts([hashtable]$Piwigo) {
    $actual = @(Invoke-OwnerDocker @('port',$Piwigo.id) 'owner_piwigo_port_inspect_failed' 30 |
        ForEach-Object { ([string]$_).Trim() } | Where-Object { $_ -ne '' } | Sort-Object)
    $expected = @('80/tcp -> 127.0.0.1:8190','8081/tcp -> 127.0.0.1:8191') | Sort-Object
    Assert-True ($actual.Count -eq $expected.Count) 'owner_piwigo_port_count_invalid'
    for ($index = 0; $index -lt $expected.Count; $index++) {
        Assert-True ($actual[$index] -eq $expected[$index]) 'owner_piwigo_non_loopback_or_wrong_port'
    }
}

function Assert-NoHostPort([hashtable]$Container) {
    $ports = @(Invoke-OwnerDocker @('port',$Container.id) 'owner_internal_port_inspect_failed' 30 |
        Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) })
    Assert-True ($ports.Count -eq 0) 'owner_internal_container_host_exposed'
}

function Invoke-OwnerRuntimeBoundary([string]$Stage) {
    $runner = Join-Path $script:ProjectRoot 'infra\scripts\private-full.ps1'
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runner runtime-owner 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    $safe = @($lines | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^PRIVATE_FULL=(?:PASS|FAIL)\b'
    })
    $pass = @($safe | Where-Object {
        $_ -match '^PRIVATE_FULL=PASS action=runtime-owner endpoint=8190_8191 evidence=RUNTIME_BOUNDARY_VALIDATED core_http=READY\b'
    })
    Assert-True ($exit -eq 0 -and $pass.Count -eq 1 -and @($safe | Where-Object { $_ -match '^PRIVATE_FULL=FAIL\b' }).Count -eq 0) ('owner_runtime_boundary_' + $Stage + '_failed')
}

function Get-OwnerSnapshot {
    $lines = @(Invoke-OwnerDocker @(
        'compose','--env-file','infra/private-full/.env.piwigo.owner',
        '-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml',
        '-p','class_archive_private_full_v3_piwigo',
        'exec','-T','--user','nginx','-e','CLASS_ARCHIVE_V4_OWNER_COLD_RESTART_SNAPSHOT=1',
        'piwigo','php','/workspace/tests/phase3/photos-app-v4-owner-cold-restart-snapshot.php'
    ) 'owner_snapshot_command_failed' 180)
    $json = @($lines | Where-Object { $_ -match '^\{.*\}$' })
    Assert-True ($json.Count -eq 1) 'owner_snapshot_json_missing'
    try { return ($json[0] | ConvertFrom-Json -ErrorAction Stop) }
    catch { Stop-OwnerColdRestart 'owner_snapshot_json_invalid' }
}

function Assert-Hash([AllowNull()][string]$Value, [string]$Code) {
    Assert-True ($Value -match '\A[a-f0-9]{64}\z') $Code
}

function Assert-OwnerSnapshot([object]$Snapshot, [string]$Stage) {
    Assert-True ($null -ne $Snapshot -and [string]$Snapshot.result -eq 'PASS') ('owner_snapshot_' + $Stage + '_result_invalid')
    Assert-True ([int]$Snapshot.schema_version -eq 18) ('owner_snapshot_' + $Stage + '_schema_invalid')
    Assert-True ([int]$Snapshot.projections.count -eq 6) ('owner_snapshot_' + $Stage + '_projection_count_invalid')
    Assert-Hash ([string]$Snapshot.projections.digest) ('owner_snapshot_' + $Stage + '_projection_digest_invalid')
    Assert-True ([int]$Snapshot.collection.pointer_count -eq 8) ('owner_snapshot_' + $Stage + '_pointer_count_invalid')
    Assert-True ([int]$Snapshot.collection.active_snapshot_count -eq 8) ('owner_snapshot_' + $Stage + '_active_snapshot_count_invalid')
    Assert-True ([int]$Snapshot.collection.active_item_count -ge 0) ('owner_snapshot_' + $Stage + '_active_item_count_invalid')
    foreach ($field in @('pointer_digest','active_snapshot_digest','active_item_digest')) {
        Assert-Hash ([string]$Snapshot.collection.$field) ('owner_snapshot_' + $Stage + '_' + $field + '_invalid')
    }
    Assert-True ([int]$Snapshot.comments.count -ge 0) ('owner_snapshot_' + $Stage + '_comment_count_invalid')
    Assert-Hash ([string]$Snapshot.comments.digest) ('owner_snapshot_' + $Stage + '_comment_digest_invalid')
    Assert-True ([int]$Snapshot.ai.asset_count -gt 0) ('owner_snapshot_' + $Stage + '_ai_asset_count_invalid')
    Assert-True ([int]$Snapshot.ai.indexed_count -eq [int]$Snapshot.ai.asset_count) ('owner_snapshot_' + $Stage + '_ai_not_fully_indexed')
    Assert-True ([int]$Snapshot.ai.job_count -ge [int]$Snapshot.ai.asset_count) ('owner_snapshot_' + $Stage + '_ai_job_count_invalid')
    Assert-True ([int]$Snapshot.ai.open_job_count -eq 0) ('owner_snapshot_' + $Stage + '_ai_reindex_jobs_open')
    Assert-Hash ([string]$Snapshot.ai.asset_digest) ('owner_snapshot_' + $Stage + '_ai_asset_digest_invalid')
    Assert-Hash ([string]$Snapshot.ai.job_digest) ('owner_snapshot_' + $Stage + '_ai_job_digest_invalid')
    Assert-True ([int]$Snapshot.spotlight_rotation.count -eq 2) ('owner_snapshot_' + $Stage + '_rotation_count_invalid')
    Assert-Hash ([string]$Snapshot.spotlight_rotation.digest) ('owner_snapshot_' + $Stage + '_rotation_digest_invalid')
}

function Assert-OwnerSnapshotSame([object]$Before, [object]$After, [string]$Stage) {
    $beforeJson = $Before | ConvertTo-Json -Depth 8 -Compress
    $afterJson = $After | ConvertTo-Json -Depth 8 -Compress
    Assert-True ($beforeJson -ceq $afterJson) ('owner_snapshot_state_changed_' + $Stage)
}

function Get-OwnerAdminUsername {
    $envPath = Join-Path $script:ProjectRoot 'infra\private-full\.env.piwigo.owner'
    $lines = @(Get-Content -LiteralPath $envPath -Encoding UTF8 | Where-Object { $_ -match '^PIWIGO_ADMIN_USERNAME=' })
    Assert-True ($lines.Count -eq 1) 'owner_admin_username_missing'
    $value = $lines[0].Substring('PIWIGO_ADMIN_USERNAME='.Length)
    Assert-True ($value -match '\A[A-Za-z0-9_.@+-]{1,100}\z') 'owner_admin_username_invalid'
    return $value
}

function Invoke-OwnerJsonGet([string]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    return Invoke-RestMethod -Uri $Uri -Method Get -WebSession $Session -Headers @{ Accept = 'application/json' } -TimeoutSec 45
}

function Assert-ImmediateV4Reads([Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    # Every endpoint is an allowlisted, server-side read. They deliberately
    # exercise the persisted V4 home/snapshot, people and structured-search
    # paths without starting an index job or a client-side media request.
    $reads = [ordered]@{
        home = 'http://127.0.0.1:8191/api/class-archive/collections/home'
        pins = 'http://127.0.0.1:8191/api/class-archive/collections/pins'
        timeline = 'http://127.0.0.1:8191/api/class-archive/timeline?limit=12'
        people = 'http://127.0.0.1:8191/api/people?size=12&withHidden=false'
        suggestions = 'http://127.0.0.1:8191/api/class-archive/search/suggestions?q=%E8%AE%B0%E5%BF%86'
        grouped = 'http://127.0.0.1:8191/api/class-archive/search/grouped?q=%E7%85%A7%E7%89%87&contextType=ALL&limit=12'
    }
    foreach ($name in $reads.Keys) {
        $script:Stage = 'immediate_read_' + $name
        try { $payload = Invoke-OwnerJsonGet ([string]$reads[$name]) $Session }
        catch { Stop-OwnerColdRestart ('owner_immediate_read_' + $name + '_failed') }
        try { $propertyCount = @($payload.PSObject.Properties).Count }
        catch { Stop-OwnerColdRestart ('owner_immediate_read_' + $name + '_shape_invalid') }
        Assert-True ($null -ne $payload -and $propertyCount -ge 1) ('owner_immediate_read_' + $name + '_empty')
    }
}

function Assert-IgnoredEvidenceRoot([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $checkout = $script:ProjectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    Assert-True ($full.StartsWith($checkout, [StringComparison]::OrdinalIgnoreCase)) 'owner_evidence_outside_checkout'
    if (-not (Test-Path -LiteralPath $full)) { [void][IO.Directory]::CreateDirectory($full) }
    $item = Get-Item -LiteralPath $full -Force
    Assert-True ($item.PSIsContainer -and (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0)) 'owner_evidence_root_untrusted'
    $relative = $item.FullName.Substring($script:ProjectRoot.Length + 1).Replace('\','/')
    & git -C $script:ProjectRoot check-ignore --quiet --no-index -- $relative
    Assert-True ($LASTEXITCODE -eq 0) 'owner_evidence_root_not_ignored'
    $tracked = @(& git -C $script:ProjectRoot ls-files -- $relative)
    Assert-True ($LASTEXITCODE -eq 0 -and $tracked.Count -eq 0) 'owner_evidence_root_tracked'
    return $item.FullName
}

function Write-OwnerEvidence([string]$Path, [hashtable]$Record) {
    $parent = Split-Path -Parent $Path
    [void](Assert-IgnoredEvidenceRoot $parent)
    $item = Get-Item -LiteralPath $parent -Force
    $full = [IO.Path]::GetFullPath($Path)
    Assert-True ($full.StartsWith($item.FullName.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) 'owner_evidence_path_invalid'
    Assert-True (-not (Test-Path -LiteralPath $full)) 'owner_evidence_already_exists'
    $json = $Record | ConvertTo-Json -Depth 10
    $stream = [IO.File]::Open($full, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $writer = [IO.StreamWriter]::new($stream, [Text.UTF8Encoding]::new($false))
        try { $writer.Write($json + [Environment]::NewLine) } finally { $writer.Dispose() }
    }
    finally { $stream.Dispose() }
}

$lease = $null
$failureCode = $null
$before = $null
$afterRestart = $null
$final = $null
$completed = $false
$restartStarted = $false
$runId = New-RunId
$evidencePath = $null

try {
    Assert-True $ConfirmOwnerPrivateRestart.IsPresent 'owner_private_restart_confirmation_required'
    Assert-True $ConfirmServingContainerRestart.IsPresent 'owner_serving_restart_confirmation_required'
    $script:ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    . (Join-Path $script:ProjectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')
    . (Join-Path $script:ProjectRoot 'tests\support\system-admin-session.ps1')
    $evidenceRoot = Assert-IgnoredEvidenceRoot (Join-Path $script:ProjectRoot '.codex-work\private-real-qa\reports\photos-app-v4-owner-cold-restart')
    $evidencePath = Join-Path $evidenceRoot ('v4-owner-cold-restart-' + $runId + '.json')

    $specs = @(
        @{ key = 'piwigo_db'; project = 'class_archive_private_full_v3_piwigo'; service = 'db'; name = 'class_archive_private_full_v3_piwigo-db-1'; group = 1 },
        @{ key = 'immich_database'; project = 'class_archive_private_full_v3_immich'; service = 'database'; name = 'class_archive_private_full_v3_immich-database-1'; group = 1 },
        @{ key = 'immich_redis'; project = 'class_archive_private_full_v3_immich'; service = 'redis'; name = 'class_archive_private_full_v3_immich-redis-1'; group = 1 },
        @{ key = 'immich_ml'; project = 'class_archive_private_full_v3_immich'; service = 'immich-machine-learning'; name = 'class_archive_private_full_v3_immich-immich-machine-learning-1'; group = 2 },
        @{ key = 'immich_server'; project = 'class_archive_private_full_v3_immich'; service = 'immich-server'; name = 'class_archive_private_full_v3_immich-immich-server-1'; group = 2 },
        @{ key = 'immich_gateway'; project = 'class_archive_private_full_v3_immich'; service = 'immich-gateway'; name = 'class_archive_private_full_v3_immich-immich-gateway-1'; group = 3 },
        @{ key = 'compat'; project = 'class_archive_private_full_v3_immich'; service = 'immich-web-compat'; name = 'class_archive_private_full_v3_immich-immich-web-compat-1'; group = 3 },
        @{ key = 'piwigo'; project = 'class_archive_private_full_v3_piwigo'; service = 'piwigo'; name = 'class_archive_private_full_v3_piwigo-piwigo-1'; group = 3 }
    )

    $script:Stage = 'owner_runtime_before'
    Invoke-OwnerRuntimeBoundary 'before'
    $script:Stage = 'owner_container_scope'
    $containers = @{}
    foreach ($spec in $specs) { $containers[[string]$spec.key] = Get-ExactOwnerContainer $spec }
    Assert-ExactPiwigoPorts $containers['piwigo']
    foreach ($key in @('piwigo_db','immich_database','immich_redis','immich_ml','immich_server','immich_gateway','compat')) {
        Assert-NoHostPort $containers[$key]
    }

    $script:Stage = 'snapshot_before'
    $before = Get-OwnerSnapshot
    Assert-OwnerSnapshot $before 'before'

    foreach ($group in 1..3) {
        foreach ($spec in @($specs | Where-Object { [int]$_.group -eq $group })) {
            $script:Stage = 'restart_' + [string]$spec.key
            $restartStarted = $true
            [void](Invoke-OwnerDocker @('restart',$containers[[string]$spec.key].id) 'owner_exact_restart_failed' 180)
            Wait-OwnerContainerReady $containers[[string]$spec.key] ('owner_restart_unhealthy_' + [string]$spec.key)
        }
    }

    $script:Stage = 'owner_runtime_after_restart'
    Invoke-OwnerRuntimeBoundary 'after_restart'
    $script:Stage = 'snapshot_after_restart'
    $afterRestart = Get-OwnerSnapshot
    Assert-OwnerSnapshot $afterRestart 'after_restart'
    Assert-OwnerSnapshotSame $before $afterRestart 'after_restart'

    $script:Stage = 'immediate_projection_session'
    $composeBase = [string[]]@(
        '-d','Ubuntu','--cd',$script:ProjectRoot,'--exec','docker','compose',
        '--env-file','infra/private-full/.env.piwigo.owner',
        '-f','infra/docker-compose.yml','-f','infra/private-full/docker-compose.override.yml',
        '-p','class_archive_private_full_v3_piwigo'
    )
    $lease = New-ClassArchiveSystemAdminSession -BaseUri ([Uri]'http://127.0.0.1:8190/') `
        -ComposeBase $composeBase -AdminUsername (Get-OwnerAdminUsername)
    Assert-ImmediateV4Reads $lease.Session
    Remove-ClassArchiveSystemAdminSession -Lease $lease
    $lease = $null

    $script:Stage = 'owner_runtime_final'
    Invoke-OwnerRuntimeBoundary 'final'
    $script:Stage = 'snapshot_final'
    $final = Get-OwnerSnapshot
    Assert-OwnerSnapshot $final 'final'
    Assert-OwnerSnapshotSame $before $final 'final'
    $completed = $true
}
catch {
    $raw = [string]$_.Exception.Message
    $failureCode = if ($raw -match '^V4_OWNER_COLD_RESTART_STOP:([a-z0-9_]{1,128})$') { [string]$Matches[1] } else { 'owner_cold_restart_unexpected' }
}
finally {
    if ($null -ne $lease) {
        try {
            Remove-ClassArchiveSystemAdminSession -Lease $lease
            $lease = $null
        }
        catch { $failureCode = 'owner_cold_restart_session_cleanup_failed' }
    }
    if ($script:ProjectRoot -ne '' -and $null -ne $evidencePath) {
        try {
            $record = [ordered]@{
                format = 1
                scope = 'PRIVATE_REAL_FULL_OWNER'
                run_id = $runId
                result = if ($completed -and $null -eq $failureCode) { 'PASS' } else { 'FAIL' }
                failure_code = $failureCode
                restart_started = $restartStarted
                stage = $script:Stage
                before = $before
                after_restart = $afterRestart
                final = $final
                assertions = $script:Assertions
                evidence = 'AGGREGATES_AND_DIGESTS_ONLY'
            }
            Write-OwnerEvidence $evidencePath $record
        }
        catch {
            $failureCode = 'owner_cold_restart_evidence_write_failed'
        }
    }
}

if (-not $completed -or $null -ne $failureCode) {
    Write-Error ('V4_OWNER_COLD_RESTART=FAIL code=' + $(if ($null -ne $failureCode) { $failureCode } else { 'owner_cold_restart_incomplete' }) + ' stage=' + $script:Stage)
    exit 1
}

Write-Output 'V4_OWNER_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO scope=OWNER_8190_8191 evidence=PRIVATE_LOCAL_IGNORED'
Write-Output 'V4_OWNER_COLD_RESTART_COMPLETE=PASS'
