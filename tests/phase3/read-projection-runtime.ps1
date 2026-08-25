[CmdletBinding()]
param(
    # Retained only as a rejected compatibility argument. Runtime proof mints
    # an exact, short-lived SYSTEM_ADMIN session and never reads a password.
    [string]$CredentialFile = '',

    [Parameter(Mandatory = $true)]
    [switch]$ConfirmSyntheticMutation,

    [Parameter(Mandatory = $true)]
    [switch]$ConfirmServiceRestart
)

# Synthetic-only Runtime evidence for the durable Phase 3.3A read model. The
# script performs one audited archive-date mutation, verifies dependency-scoped
# point refresh, rolls the source state back, then restarts only the synthetic
# Piwigo/Gateway and compatibility BFF containers. It never addresses the
# private-QA compose projects, reads private source media, or rotates a human
# account password for test convenience.

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
        $operation = if ($Arguments.Count -gt 0) { $Arguments[0] } else { 'unknown' }
        $diagnostic = (($lines | Select-Object -First 2) -join ' ').Trim()
        if ($diagnostic.Length -gt 240) { $diagnostic = $diagnostic.Substring(0, 240) }
        if ($diagnostic -ne '') { Write-Host ('READ_PROJECTION_RUNTIME_DOCKER_DIAGNOSTIC=' + $diagnostic) }
        throw ('read_projection_runtime_docker_failed_' + $exit + '_' + $operation)
    }
    return @($lines | ForEach-Object { [string]$_ })
}

function Get-Container([string]$Project, [string]$Service) {
    $lines = @(Invoke-WslDocker @(
        'ps', '--filter', "label=com.docker.compose.project=$Project",
        '--filter', "label=com.docker.compose.service=$Service",
        '--format', '{{.ID}}|{{.Names}}'
    ) | Where-Object { $_ -match '\A[0-9a-f]{12,64}\|[A-Za-z0-9_.-]+\z' })
    Assert-True ($lines.Count -eq 1) 'read_projection_runtime_container_scope_invalid'
    $parts = $lines[0].Split('|', 2)
    return [ordered]@{ id = $parts[0]; name = $parts[1] }
}

function Get-Snapshot {
    $lines = @(Invoke-WslDocker @(
        'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/tests/phase3/read-projection-runtime-snapshot.php'
    ))
    $jsonLine = @($lines | Where-Object { $_.TrimStart().StartsWith('{') } | Select-Object -Last 1)
    Assert-True ($jsonLine.Count -eq 1) 'read_projection_runtime_snapshot_missing'
    $snapshot = $jsonLine[0] | ConvertFrom-Json
    Assert-True ($snapshot.result -eq 'PASS' -and [int]$snapshot.schema_version -eq 14 -and [int]$snapshot.incremental_contract -eq 1) 'read_projection_runtime_snapshot_invalid'
    return $snapshot
}

function Invoke-JsonGet([string]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session) {
    return Invoke-RestMethod -Uri $Uri -Method Get -WebSession $Session -Headers @{ Accept = 'application/json' } -TimeoutSec 30
}

function ConvertTo-StableTimelinePayload([object]$Payload) {
    # presentation_epoch and next_cursor are deliberately revision-bound and
    # must change after a write/rollback cycle. Compare only the authorized
    # timeline content so the recovery test does not mistake safe cache
    # invalidation for business-payload drift.
    return ([ordered]@{
        total = $Payload.total
        count = $Payload.count
        limit = $Payload.limit
        groups = @($Payload.groups)
        hasMore = $Payload.hasMore
    } | ConvertTo-Json -Depth 30 -Compress)
}

function Invoke-ArchiveMutation(
    [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
    [string]$Csrf,
    [string]$PhotoId,
    [AllowNull()][string]$ArchiveDate,
    [string]$Precision,
    [AllowNull()][string]$EventLabel,
    [string]$Reason
) {
    $body = [ordered]@{
        csrfToken = $Csrf
        photoIds = @($PhotoId)
        archiveDate = $ArchiveDate
        datePrecision = $Precision
        eventId = $null
        eventLabel = $EventLabel
        albumAddIds = @()
        albumRemoveIds = @()
        era = $null
        eraConfirmed = $false
        reason = $Reason
    } | ConvertTo-Json -Depth 8 -Compress
    return Invoke-RestMethod -Uri 'http://127.0.0.1:8091/api/class-archive/manage/archive/bulk' `
        -Method Post -WebSession $Session -ContentType 'application/json; charset=utf-8' `
        -Headers @{ Accept = 'application/json'; 'X-Class-Archive-CSRF' = $Csrf } `
        -Body ([Text.Encoding]::UTF8.GetBytes($body)) -TimeoutSec 120
}

function Projection([object]$Snapshot, [string]$Kind) {
    return $Snapshot.projections.PSObject.Properties[$Kind].Value
}

function Assert-ProjectionSame([object]$Left, [object]$Right, [string]$Kind, [string]$Code) {
    $a = Projection $Left $Kind
    $b = Projection $Right $Kind
    foreach ($field in @('state','generation','built_at','source_revision','payload_digest','dependency_revision','item_count')) {
        Assert-True ([string]$a.$field -ceq [string]$b.$field) ($Code + '_' + $Kind.ToLowerInvariant() + '_' + $field)
    }
}

function Assert-ProjectionRebound([object]$Left, [object]$Right, [string]$Kind, [bool]$DigestRestored, [string]$Code) {
    $a = Projection $Left $Kind
    $b = Projection $Right $Kind
    foreach ($field in @('state','generation','item_count')) {
        Assert-True ([string]$a.$field -ceq [string]$b.$field) ($Code + '_' + $Kind.ToLowerInvariant() + '_' + $field)
    }
    foreach ($field in @('source_revision','payload_digest','dependency_revision')) {
        if ($DigestRestored) {
            Assert-True ([string]$a.$field -ceq [string]$b.$field) ($Code + '_' + $Kind.ToLowerInvariant() + '_' + $field)
        } else {
            Assert-True ([string]$a.$field -cne [string]$b.$field) ($Code + '_' + $Kind.ToLowerInvariant() + '_' + $field)
        }
    }
}

function Wait-Healthy([string]$ContainerId, [string]$Code) {
    $deadline = [DateTime]::UtcNow.AddSeconds(120)
    do {
        $state = @(Invoke-WslDocker @('inspect', '--format', '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}', $ContainerId) | Select-Object -Last 1)
        if ($state.Count -eq 1 -and $state[0].Trim() -eq 'healthy') { return }
        Start-Sleep -Milliseconds 500
    } while ([DateTime]::UtcNow -lt $deadline)
    throw $Code
}

function Invoke-Fixture([string]$Action, [string]$Run) {
    $lines = @(Invoke-WslDocker @(
        'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_ALLOW_READ_PROJECTION_RUNTIME_FIXTURE=1',
        'piwigo', 'php', '/workspace/tests/phase3/read-projection-runtime-fixture.php', $Action, $Run
    ))
    $expected = if ($Action -eq 'prepare') { 'READ_PROJECTION_RUNTIME_FIXTURE=READY' } else { 'READ_PROJECTION_RUNTIME_FIXTURE=CLEAN' }
    Assert-True (@($lines | Where-Object { $_ -like ($expected + '*') }).Count -eq 1) ('read_projection_runtime_fixture_' + $Action + '_failed')
}

try {
    Assert-True $ConfirmSyntheticMutation.IsPresent 'read_projection_runtime_synthetic_confirmation_required'
    Assert-True $ConfirmServiceRestart.IsPresent 'read_projection_runtime_restart_confirmation_required'
    $script:ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    Assert-True ([string]::IsNullOrEmpty($CredentialFile)) 'read_projection_runtime_password_credential_rejected'
    . (Join-Path $script:ProjectRoot 'tests\support\system-admin-session.ps1')
    $adminUsername = ''
    foreach ($line in [IO.File]::ReadAllLines((Join-Path $script:ProjectRoot '.env.piwigo'))) {
        if ($line.StartsWith('PIWIGO_ADMIN_USERNAME=')) {
            $adminUsername = $line.Substring('PIWIGO_ADMIN_USERNAME='.Length)
            break
        }
    }
    Assert-True ($adminUsername -match '^[A-Za-z0-9_.@+-]{1,100}$') 'read_projection_runtime_admin_username_invalid'

    $script:Stage = 'container_discovery'
    $piwigo = Get-Container 'class_archive_piwigo' 'piwigo'
    $compat = Get-Container 'class-archive-immich-spike' 'immich-web-compat'
    Assert-True ($piwigo.name -eq 'class_archive_piwigo-piwigo-1') 'read_projection_runtime_piwigo_name_invalid'
    Assert-True ($compat.name -eq 'class-archive-immich-spike-immich-web-compat-1') 'read_projection_runtime_compat_name_invalid'
    $script:Stage = 'port_boundary'
    $piwigoPorts = @(Invoke-WslDocker @('port', $piwigo.id))
    Assert-True ($piwigoPorts -contains '80/tcp -> 127.0.0.1:8090') 'read_projection_runtime_core_not_loopback'
    Assert-True ($piwigoPorts -contains '8081/tcp -> 127.0.0.1:8091') 'read_projection_runtime_compat_not_loopback'
    Assert-True (@(Invoke-WslDocker @('port', $compat.id)).Count -eq 0) 'read_projection_runtime_bff_host_port_exposed'
    $script:Stage = 'mount_boundary'
    $mountNames = @(Invoke-WslDocker @('inspect', '--format', '{{range .Mounts}}{{println .Name}}{{end}}', $piwigo.id))
    Assert-True (@($mountNames | Where-Object { $_ -match 'private[_-]qa' }).Count -eq 0) 'read_projection_runtime_private_mount_detected'

    $random = New-Object byte[] 8
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($random) } finally { $rng.Dispose() }
    $run = (($random | ForEach-Object { $_.ToString('x2') }) -join '')
    Invoke-Fixture 'prepare' $run
    $fixturePrepared = $true
    $lease = $null

    try {
        $script:Stage = 'admin_session'
        $composeBase = [string[]]@(
            '-d', 'Ubuntu', '--cd', $script:ProjectRoot, '--exec',
            'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
        )
        $lease = New-ClassArchiveSystemAdminSession `
            -BaseUri ([Uri]'http://127.0.0.1:8090/') `
            -ComposeBase $composeBase `
            -AdminUsername $adminUsername
        $session = $lease.Session
        $productState = Invoke-JsonGet 'http://127.0.0.1:8091/api/class-archive/product-state' $session
        $csrf = [string]$productState.csrfToken
        Assert-True ($productState.role -eq 'SYSTEM_ADMIN' -and $csrf.Length -ge 16 `
            -and [string]$productState.presentationEpoch -match '^[a-f0-9]{64}$' `
            -and [string]$productState.cacheScope -match '^[a-f0-9]{32}$') 'read_projection_runtime_admin_state_invalid'

        $beforeTimelinePayload = ConvertTo-StableTimelinePayload (Invoke-JsonGet 'http://127.0.0.1:8091/api/class-archive/timeline' $session)
        $before = Get-Snapshot
        $candidate = $before.mutation_candidate
        $photoId = [string]$candidate.id
        $alternateYear = if ([string]$candidate.archive_date -like '2098-*') { '2097' } else { '2098' }
        $mutated = $false
        try {
        $script:Stage = 'mutation'
        [void](Invoke-ArchiveMutation $session $csrf $photoId $alternateYear 'YEAR' $null '合成数据投影增量失效验收')
        $mutated = $true
        $afterMutation = Get-Snapshot

        Assert-True ([string]$afterMutation.mutation_candidate.archive_date -eq ($alternateYear + '-01-01')) 'read_projection_runtime_mutation_date_missing'
        Assert-True ([string]$afterMutation.mutation_candidate.date_precision -eq 'YEAR') 'read_projection_runtime_mutation_precision_missing'
        Assert-True ([string]$afterMutation.read_photo.generation -eq [string]$before.read_photo.generation) 'read_projection_runtime_catalog_generation_replaced'
        Assert-True ([int]$afterMutation.read_photo.count -eq [int]$before.read_photo.count) 'read_projection_runtime_read_photo_count_changed'
        Assert-True ([string]$afterMutation.read_photo.non_target_storage_digest -eq [string]$before.read_photo.non_target_storage_digest) 'read_projection_runtime_unrelated_read_photo_changed'
        Assert-True ([int]$afterMutation.derivatives.files -eq [int]$before.derivatives.files -and [long]$afterMutation.derivatives.bytes -eq [long]$before.derivatives.bytes) 'read_projection_runtime_derivative_changed_during_mutation'
        foreach ($kind in @('ALBUMS','PEOPLE')) {
            Assert-ProjectionRebound $before $afterMutation $kind $false 'read_projection_runtime_unrelated_projection_rebind_failed'
        }
        foreach ($kind in @('TIMELINE','MEMORIES')) {
            Assert-True ((Projection $before $kind).generation -ne (Projection $afterMutation $kind).generation) ('read_projection_runtime_affected_projection_not_rebuilt_' + $kind.ToLowerInvariant())
        }
        Assert-True ((Projection $before 'PHOTO_CATALOG').generation -eq (Projection $afterMutation 'PHOTO_CATALOG').generation) 'read_projection_runtime_catalog_generation_not_incremental'
        Assert-True ((Projection $before 'PHOTO_CATALOG').source_revision -ne (Projection $afterMutation 'PHOTO_CATALOG').source_revision) 'read_projection_runtime_catalog_digest_not_changed'

        $restorePrecision = [string]$candidate.date_precision
        $restoreDate = if ($null -eq $candidate.archive_date) { $null } else {
            $value = [string]$candidate.archive_date
            if ($restorePrecision -eq 'YEAR') { $value.Substring(0, 4) }
            elseif ($restorePrecision -eq 'MONTH') { $value.Substring(0, 7) }
            else { $value.Substring(0, 10) }
        }
        $script:Stage = 'rollback'
        [void](Invoke-ArchiveMutation $session $csrf $photoId $restoreDate $restorePrecision $candidate.event_label '合成数据投影增量失效回滚')
        $mutated = $false
        $afterRollback = Get-Snapshot
        Assert-True ([string]$afterRollback.mutation_candidate.archive_date -eq [string]$candidate.archive_date) 'read_projection_runtime_rollback_date_failed'
        Assert-True ([string]$afterRollback.mutation_candidate.date_precision -eq $restorePrecision) 'read_projection_runtime_rollback_precision_failed'
        Assert-True ([string]$afterRollback.mutation_candidate.date_source -eq [string]$candidate.date_source) 'read_projection_runtime_rollback_source_failed'
        Assert-True ([string]$afterRollback.read_photo.non_target_storage_digest -eq [string]$before.read_photo.non_target_storage_digest) 'read_projection_runtime_rollback_unrelated_row_changed'
        Assert-True ([int]$afterRollback.derivatives.files -eq [int]$before.derivatives.files -and [long]$afterRollback.derivatives.bytes -eq [long]$before.derivatives.bytes) 'read_projection_runtime_derivative_changed_during_rollback'
        foreach ($kind in @('ALBUMS','PEOPLE')) {
            Assert-ProjectionRebound $before $afterRollback $kind $true 'read_projection_runtime_rollback_unrelated_projection_changed'
        }
        Assert-True ((Projection $before 'PHOTO_CATALOG').source_revision -eq (Projection $afterRollback 'PHOTO_CATALOG').source_revision) 'read_projection_runtime_rollback_catalog_digest_failed'
        foreach ($kind in @('TIMELINE','MEMORIES')) {
            Assert-True ((Projection $before $kind).state -eq (Projection $afterRollback $kind).state) ('read_projection_runtime_rollback_projection_state_failed_' + $kind.ToLowerInvariant())
            Assert-True ((Projection $before $kind).item_count -eq (Projection $afterRollback $kind).item_count) ('read_projection_runtime_rollback_projection_count_failed_' + $kind.ToLowerInvariant())
            Assert-True ((Projection $afterMutation $kind).generation -ne (Projection $afterRollback $kind).generation) ('read_projection_runtime_rollback_projection_generation_failed_' + $kind.ToLowerInvariant())
        }
        $afterRollbackTimelinePayload = ConvertTo-StableTimelinePayload (Invoke-JsonGet 'http://127.0.0.1:8091/api/class-archive/timeline' $session)
        Assert-True ($beforeTimelinePayload -ceq $afterRollbackTimelinePayload) 'read_projection_runtime_rollback_timeline_payload_failed'

        $script:Stage = 'restart'
        $restartClock = [Diagnostics.Stopwatch]::StartNew()
        [void](Invoke-WslDocker @('restart', $compat.id, $piwigo.id))
        Wait-Healthy $compat.id 'read_projection_runtime_compat_restart_unhealthy'
        Wait-Healthy $piwigo.id 'read_projection_runtime_piwigo_restart_unhealthy'
        $restartClock.Stop()
        $httpClock = [Diagnostics.Stopwatch]::StartNew()
        $script:Stage = 'first_http'
        $timeline = Invoke-JsonGet 'http://127.0.0.1:8091/api/class-archive/timeline' $session
        $httpClock.Stop()
        Assert-True ([int]$timeline.total -ge 1) 'read_projection_runtime_first_http_unavailable'
        [void](Invoke-JsonGet 'http://127.0.0.1:8091/api/class-archive/albums' $session)
        $afterRestart = Get-Snapshot
        foreach ($kind in @('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT')) {
            Assert-ProjectionSame $afterRollback $afterRestart $kind 'read_projection_runtime_restart_rebuilt_projection'
        }
        Assert-True ([string]$afterRestart.read_photo.non_target_storage_digest -eq [string]$afterRollback.read_photo.non_target_storage_digest `
            -and [string]$afterRestart.read_photo.target_storage.row_digest -eq [string]$afterRollback.read_photo.target_storage.row_digest `
            -and [string]$afterRestart.read_photo.target_storage.built_at -eq [string]$afterRollback.read_photo.target_storage.built_at) 'read_projection_runtime_restart_rebuilt_read_photo'
        Assert-True ([int]$afterRestart.derivatives.files -eq [int]$afterRollback.derivatives.files -and [long]$afterRestart.derivatives.bytes -eq [long]$afterRollback.derivatives.bytes) 'read_projection_runtime_restart_regenerated_derivatives'

        $report = [ordered]@{
            version = 1
            result = 'PASS'
            environment = 'SYNTHETIC_ONLY'
            schema_version = 14
            mutation = [ordered]@{
                target_rows = 1
                catalog_generation_retained = $true
                unrelated_read_photo_digest_retained = $true
                affected_projection_kinds = @('PHOTO_CATALOG','TIMELINE','MEMORIES')
                unchanged_projection_kinds = @('ALBUMS','PEOPLE')
                catalog_digest_restored = $true
                timeline_payload_restored = $true
                memories_state_restored = $true
            }
            restart = [ordered]@{
                services = @('piwigo-gateway','compatibility-bff')
                healthy_wait_ms = [int]$restartClock.ElapsedMilliseconds
                first_authenticated_http_ms = [int]$httpClock.ElapsedMilliseconds
                projections_rebuilt = 0
                derivative_files_regenerated = 0
            }
            catalog = [ordered]@{
                count = [int]$afterRestart.read_photo.count
                derivative_files = [int]$afterRestart.derivatives.files
                derivative_bytes = [long]$afterRestart.derivatives.bytes
            }
            assertions = $script:Assertions
        }
        $reportRoot = Join-Path $script:ProjectRoot '.codex-work\reports\phase33a'
        [void][IO.Directory]::CreateDirectory($reportRoot)
        $reportPath = Join-Path $reportRoot ('read-projection-runtime-' + [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss') + '.json')
        $report | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath $reportPath -Encoding UTF8
        Write-Output ('READ_PROJECTION_RUNTIME=PASS assertions=' + $script:Assertions + ' restart_ms=' + $restartClock.ElapsedMilliseconds + ' first_http_ms=' + $httpClock.ElapsedMilliseconds)
        Write-Output ('READ_PROJECTION_RUNTIME_REPORT=' + $reportPath)
        }
        finally {
            if ($mutated) {
                $restorePrecision = [string]$candidate.date_precision
                $restoreDate = if ($null -eq $candidate.archive_date) { $null } else {
                    $value = [string]$candidate.archive_date
                    if ($restorePrecision -eq 'YEAR') { $value.Substring(0, 4) }
                    elseif ($restorePrecision -eq 'MONTH') { $value.Substring(0, 7) }
                    else { $value.Substring(0, 10) }
                }
                [void](Invoke-ArchiveMutation $session $csrf $photoId $restoreDate $restorePrecision $candidate.event_label '合成数据投影验收异常回滚')
            }
        }
    }
    finally {
        if ($null -ne $lease) { Remove-ClassArchiveSystemAdminSession -Lease $lease }
        if ($fixturePrepared) { Invoke-Fixture 'cleanup' $run }
    }
}
catch {
    $rawCode = [string]$_.Exception.Message
    $code = $rawCode
    if ($code -notmatch '\A[a-z0-9_]{1,128}\z') {
        $code = 'unexpected_runtime_failure'
        if ($env:CLASS_ARCHIVE_RUNTIME_DEBUG -eq '1') {
            $diagnostic = [regex]::Replace($rawCode, '[^A-Za-z0-9_.-]', '_')
            if ($diagnostic.Length -gt 160) { $diagnostic = $diagnostic.Substring(0, 160) }
            Write-Warning ('READ_PROJECTION_RUNTIME_DIAGNOSTIC=' + $diagnostic)
        }
    }
    Write-Error ('READ_PROJECTION_RUNTIME=FAIL code=' + $code + ' stage=' + $script:Stage)
    exit 1
}
