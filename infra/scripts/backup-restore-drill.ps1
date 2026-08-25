[CmdletBinding()]
param(
    [switch]$ConfirmSyntheticRestore
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $ConfirmSyntheticRestore) {
    throw 'Refusing destructive restore drill without -ConfirmSyntheticRestore.'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$composeBase = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--env-file', '.env.piwigo',
    '-f', 'infra/docker-compose.yml'
)
$spikeComposeBase = @(
    '-d', 'Ubuntu',
    '--cd', $projectRoot,
    '--',
    'docker', 'compose',
    '--project-directory', 'infra/immich-spike',
    '--env-file', 'infra/immich-spike/.env',
    '-f', 'infra/immich-spike/docker-compose.yml',
    '--profile', 'immich-spike'
)
$immichServerContainer = 'class-archive-immich-spike-immich-server-1'
$runStamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$workRoot = Join-Path $projectRoot ('.codex-work\backup-restore-drill\' + $runStamp)
New-Item -ItemType Directory -Path $workRoot -Force | Out-Null

function Invoke-Compose {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    & "$env:SystemRoot\System32\wsl.exe" @($composeBase + $Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose command failed: $($Arguments -join ' ')"
    }
}

function Invoke-ImmichSpikeCompose {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    & "$env:SystemRoot\System32\wsl.exe" @($spikeComposeBase + $Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "Immich spike compose command failed: $($Arguments -join ' ')"
    }
}

function Get-DockerInspectOrNull {
    param([Parameter(Mandatory = $true)][string]$Name)
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $raw = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu -- docker inspect $Name 2>&1)
        $code = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }
    if ($code -ne 0) { return $null }
    $parsed = $raw | ConvertFrom-Json -ErrorAction Stop
    if ($parsed.Count -ne 1) { throw 'Docker inspect result was ambiguous.' }
    return $parsed[0]
}

function Detach-ImmichOriginalMounts {
    # Immich is intentionally read-only, but Docker still treats its live or
    # stopped container as a holder of the two Piwigo original volumes. A
    # destructive Piwigo restore must first remove only that disposable
    # container; it never removes Immich DB/upload/model state or any Piwigo
    # volume outside the drill's explicit list.
    $record = Get-DockerInspectOrNull -Name $immichServerContainer
    if ($null -eq $record) { return @{ Detached = $false; WasRunning = $false } }
    if (
        $record.Config.Labels.'com.docker.compose.project' -ne 'class-archive-immich-spike' -or
        $record.Config.Labels.'com.docker.compose.service' -ne 'immich-server'
    ) {
        throw 'Refusing to detach an unexpected Immich container.'
    }
    $mounts = @($record.Mounts)
    # Docker represents the timezone bind mount alongside named volumes.  Under
    # StrictMode, do not dereference a `Name` property on that bind object:
    # examine the property bag and accept only the two explicit read-only
    # volume mounts that form the original-media boundary.
    $uploadMount = @($mounts | Where-Object {
        $type = $_.PSObject.Properties['Type']
        $name = $_.PSObject.Properties['Name']
        $destination = $_.PSObject.Properties['Destination']
        $readWrite = $_.PSObject.Properties['RW']
        $null -ne $type -and $null -ne $name -and $null -ne $destination -and $null -ne $readWrite -and
        $type.Value -eq 'volume' -and $name.Value -eq 'class_archive_piwigo_uploads' -and
        $destination.Value -eq '/external/piwigo-upload' -and $readWrite.Value -eq $false
    })
    $galleryMount = @($mounts | Where-Object {
        $type = $_.PSObject.Properties['Type']
        $name = $_.PSObject.Properties['Name']
        $destination = $_.PSObject.Properties['Destination']
        $readWrite = $_.PSObject.Properties['RW']
        $null -ne $type -and $null -ne $name -and $null -ne $destination -and $null -ne $readWrite -and
        $type.Value -eq 'volume' -and $name.Value -eq 'class_archive_piwigo_galleries' -and
        $destination.Value -eq '/external/piwigo-galleries' -and $readWrite.Value -eq $false
    })
    if ($uploadMount.Count -ne 1 -or $galleryMount.Count -ne 1) {
        throw 'Immich original mount is not the expected read-only boundary.'
    }
    $wasRunning = [bool]$record.State.Running
    # Compose writes progress text to stdout.  Suppress that implementation
    # detail here so this function returns exactly one state hashtable.
    $null = Invoke-ImmichSpikeCompose -Arguments @('rm', '-s', '-f', 'immich-server')
    if ($null -ne (Get-DockerInspectOrNull -Name $immichServerContainer)) {
        throw 'Immich original-mount container was not detached.'
    }
    return @{ Detached = $true; WasRunning = $wasRunning }
}

function Restore-ImmichOriginalMounts {
    param([Parameter(Mandatory = $true)][hashtable]$State)
    if (-not [bool]$State.Detached -or -not [bool]$State.WasRunning) { return }
    $null = Invoke-ImmichSpikeCompose -Arguments @('up', '-d', 'immich-server')
    for ($attempt = 1; $attempt -le 90; $attempt++) {
        $record = Get-DockerInspectOrNull -Name $immichServerContainer
        if ($null -ne $record -and $record.State.Running -eq $true -and $record.State.Health.Status -eq 'healthy') {
            return
        }
        Start-Sleep -Seconds 1
    }
    throw 'Immich server did not return healthy after the Piwigo restore.'
}

function Invoke-Dev {
    param([Parameter(Mandatory = $true)][string]$Action)
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') $Action
    if ($LASTEXITCODE -ne 0) {
        throw "Project verification failed: $Action"
    }
}

function Invoke-DevWithEvidence {
    param(
        [Parameter(Mandatory = $true)][string]$Action,
        [Parameter(Mandatory = $true)][string]$ArtifactName
    )
    # A deliberately failing nested gate writes its summary to stderr. Treat
    # that as evidence, not as a PowerShell-native terminating error, so the
    # exact output reaches the ignored drill artifact before we fail this
    # destructive recovery run.
    $priorErrorPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') $Action 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $priorErrorPreference
    }
    $artifact = Join-Path $workRoot $ArtifactName
    [IO.File]::WriteAllText($artifact, (($lines | ForEach-Object { [string]$_ }) -join [Environment]::NewLine) + [Environment]::NewLine, [Text.UTF8Encoding]::new($false))
    $lines | ForEach-Object { Write-Output $_ }
    if ($exitCode -ne 0) {
        throw "Project verification failed: $Action (see $ArtifactName)."
    }
}

function Get-RestoreFixture {
    $output = @(& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/capture-restore-fixture.php'
    )) 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw 'Could not capture the deterministic restore fixture.'
    }
    $jsonLine = @($output | Where-Object { ([string]$_).TrimStart().StartsWith('{') }) | Select-Object -Last 1
    if ($null -eq $jsonLine) {
        throw 'The restore fixture did not emit JSON.'
    }
    try {
        return ([string]$jsonLine | ConvertFrom-Json)
    }
    catch {
        throw 'The restore fixture JSON was invalid.'
    }
}

function Invoke-ReadProjectionRebuild {
    $output = @(& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/rebuild-photo-read-projection.php', '--scope=all', '--json'
    )) 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw 'Could not rebuild read projections after restore.'
    }
    $jsonLine = @($output | Where-Object { ([string]$_).TrimStart().StartsWith('{') }) | Select-Object -Last 1
    if ($null -eq $jsonLine) {
        throw 'Read projection rebuild did not emit JSON.'
    }
    try {
        $record = ([string]$jsonLine | ConvertFrom-Json)
    }
    catch {
        throw 'Read projection rebuild JSON was invalid.'
    }
    $catalog = @($record.projections | Where-Object { $_.kind -eq 'PHOTO_CATALOG' })
    $aggregateKinds = @('TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES', 'SPOTLIGHT')
    $activeAggregates = @($record.projections | Where-Object {
        $_.kind -in $aggregateKinds -and $_.state -eq 'ACTIVE'
    })
    if (
        $record.result -ne 'PASS' -or
        [int]$record.count -ne 72 -or
        $catalog.Count -ne 1 -or
        $catalog[0].state -ne 'ACTIVE' -or
        [int]$catalog[0].count -ne 72 -or
        $activeAggregates.Count -ne 5
    ) {
        throw 'Read projections were not rebuilt to PHOTO_CATALOG ACTIVE/72 plus five ACTIVE aggregates.'
    }
    $record | Add-Member -NotePropertyName active_aggregate_count -NotePropertyValue $activeAggregates.Count
    return $record
}

function Invoke-DerivativeWarmup {
    $profiles = @('square', 'thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview')
    $output = @(& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/warm-photo-cache.php',
        '--scope=all', "--profiles=$($profiles -join ',')", '--json'
    )) 2>&1)
    $exitCode = $LASTEXITCODE
    if ($exitCode -ne 0) {
        throw 'Could not rebuild Piwigo derivatives after restore.'
    }
    $jsonLine = @($output | Where-Object { ([string]$_).TrimStart().StartsWith('{') }) | Select-Object -Last 1
    if ($null -eq $jsonLine) {
        throw 'Derivative warmup did not emit JSON.'
    }
    try {
        $record = ([string]$jsonLine | ConvertFrom-Json)
    }
    catch {
        throw 'Derivative warmup JSON was invalid.'
    }
    $expectedChecks = 72 * $profiles.Count
    $countFields = @(
        'selected_images', 'checked', 'cached', 'generated', 'would_generate',
        'source_reuse', 'mode_repairs', 'would_repair_mode', 'metadata_normalized',
        'would_normalize_metadata', 'queued', 'queue_quarantined',
        'queue_completed', 'queue_retained'
    )
    foreach ($field in $countFields) {
        if ($null -eq $record.PSObject.Properties[$field] -or [int]$record.$field -lt 0) {
            throw "Derivative warmup emitted an invalid count: $field"
        }
    }
    if (
        [int]$record.warmup_version -ne 1 -or
        $record.result -ne 'PASS' -or
        $record.scope -ne 'all' -or
        [bool]$record.dry_run -or
        (@($record.profiles) -join ',') -ne ($profiles -join ',') -or
        [int]$record.selected_images -ne 72 -or
        [int]$record.checked -ne $expectedChecks -or
        ([int]$record.cached + [int]$record.generated) -ne $expectedChecks -or
        [int]$record.would_generate -ne 0 -or
        [int]$record.would_repair_mode -ne 0 -or
        [int]$record.would_normalize_metadata -ne 0 -or
        [int]$record.source_reuse -gt $expectedChecks -or
        [int]$record.metadata_normalized -gt 72 -or
        [int]$record.queue_quarantined -ne 0 -or
        [int]$record.queue_retained -ne 0 -or
        ([int]$record.queue_completed + [int]$record.queue_quarantined + [int]$record.queue_retained) -ne [int]$record.queued
    ) {
        throw 'Piwigo derivatives were not rebuilt for all 72 images and seven approved recovery profiles.'
    }
    return $record
}

function Save-JsonArtifact {
    param([Parameter(Mandatory = $true)][string]$Name, [Parameter(Mandatory = $true)]$Value)
    $path = Join-Path $workRoot $Name
    [IO.File]::WriteAllText($path, ($Value | ConvertTo-Json -Depth 20), [Text.UTF8Encoding]::new($false))
    return $path
}

function Assert-CanonicalFixture {
    param([Parameter(Mandatory = $true)]$Fixture)
    if ([int]$Fixture.fixture_version -ne 6 -or [int]$Fixture.class_identity_schema_version -ne 14) {
        throw 'Refusing destructive drill: restore fixture does not attest the ClassIdentity v14 product schema.'
    }
    $v14BusinessState = @(
        'person',
        'person_merge',
        'person_photo_rule',
        'album',
        'spotlight',
        'photo_source',
        'photo_duplicate',
        'batch_operation',
        'batch_operation_item',
        'private_library_collection',
        'private_library_folder',
        'private_library_import',
        'private_library_import_item',
        'migration'
    )
    foreach ($name in $v14BusinessState) {
        $property = $Fixture.summary.PSObject.Properties[$name]
        if ($null -eq $property -or $null -eq $property.Value.count -or [string]$property.Value.sha256 -notmatch '^[0-9a-f]{64}$') {
            throw "Refusing destructive drill: restore fixture is missing deterministic v14 business state: $name"
        }
    }
    if (
        $Fixture.projection_recovery.policy -ne 'REBUILD_FROM_BUSINESS_TRUTH' -or
        $Fixture.projection_recovery.projection -ne 'ALL' -or
        [int]$Fixture.projection_recovery.expected_count -ne 72 -or
        (@($Fixture.projection_recovery.required_active) -join ',') -ne 'PHOTO_CATALOG,TIMELINE,ALBUMS,PEOPLE,MEMORIES,SPOTLIGHT'
    ) {
        throw 'Refusing destructive drill: fixture projection recovery contract is invalid.'
    }
    if (
        [int]$Fixture.summary.images.count -ne 72 -or
        [int]$Fixture.summary.physical_originals.count -ne 72 -or
        [int]$Fixture.summary.multi_album_images -ne 8
    ) {
        throw 'Refusing destructive drill: the running fixture is not the expected 72/72/8 synthetic baseline.'
    }
}

function Assert-ExpectedVolume {
    param([Parameter(Mandatory = $true)][string]$Name, [Parameter(Mandatory = $true)][string]$LogicalName)
    $raw = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu -- docker volume inspect $Name 2>&1)
    if ($LASTEXITCODE -ne 0) { throw "Expected volume is absent: $Name" }
    $value = @((($raw -join "`n") | ConvertFrom-Json))[0]
    $labels = $value.Labels
    if (
        $labels.'com.docker.compose.project' -ne 'class_archive_piwigo' -or
        $labels.'com.docker.compose.volume' -ne $LogicalName
    ) {
        throw "Refusing volume outside this Class Archive compose scope: $Name"
    }
}

$targetVolumes = @(
    @{ Name = 'class_archive_piwigo_data'; Logical = 'piwigo_data' },
    @{ Name = 'class_archive_piwigo_uploads'; Logical = 'piwigo_uploads' },
    @{ Name = 'class_archive_piwigo_galleries'; Logical = 'piwigo_galleries' },
    @{ Name = 'class_archive_piwigo_derivatives'; Logical = 'piwigo_derivatives' },
    @{ Name = 'class_archive_piwigo_db'; Logical = 'piwigo_db' },
    @{ Name = 'class_archive_piwigo_scripts'; Logical = 'piwigo_scripts' }
)
$backupVolume = @{ Name = 'class_archive_piwigo_backups'; Logical = 'backups' }
$destructionStarted = $false
$serviceRtoStart = $null
$immichMountState = @{ Detached = $false; WasRunning = $false }

try {
    Invoke-Dev 'baseline-verify'
    $before = Get-RestoreFixture
    Assert-CanonicalFixture -Fixture $before
    [void](Save-JsonArtifact -Name 'before.json' -Value $before)

    Invoke-Dev 'backup'
    $backupStatusRaw = @(& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @(
        'exec', '-T', 'piwigo', 'cat', '/var/www/html/piwigo/_data/class-archive/backup-freshness.json'
    )) 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'Could not read the safe backup freshness record.' }
    $backupStatus = (($backupStatusRaw -join "`n") | ConvertFrom-Json)
    if ($backupStatus.state -ne 'FRESH' -or $backupStatus.bundle -notmatch '^class-archive-[0-9]{8}T[0-9]{6}Z$') {
        throw 'Latest backup was not independently checksum-verified.'
    }
    $bundle = [string]$backupStatus.bundle
    [void](Save-JsonArtifact -Name 'backup-status.json' -Value $backupStatus)

    Invoke-Compose -Arguments @(
        '--profile', 'ops', 'run', '--rm',
        '-e', 'CLASS_ARCHIVE_RESTORE_DRILL=true',
        '-e', 'CLASS_ARCHIVE_RESTORE_CONFIRM=RESTORE_SYNTHETIC_FIXTURE_ONLY',
        '-e', 'CLASS_ARCHIVE_RESTORE_PRECHECK=true',
        '-e', "CLASS_ARCHIVE_RESTORE_BUNDLE=$bundle",
        'restore'
    )

    foreach ($volume in $targetVolumes) { Assert-ExpectedVolume -Name $volume.Name -LogicalName $volume.Logical }
    Assert-ExpectedVolume -Name $backupVolume.Name -LogicalName $backupVolume.Logical

    # The isolated Immich server may be running during a local frontend spike.
    # It has the Piwigo originals mounted read-only, yet Docker rightfully
    # refuses any volume removal until that one disposable container is
    # detached. The identity/ACL stack is not involved in this pause.
    $immichMountState = Detach-ImmichOriginalMounts

    Invoke-Compose -Arguments @('stop', 'piwigo', 'db')
    Invoke-Compose -Arguments @('rm', '-s', '-f', 'piwigo', 'db')
    $serviceRtoStart = Get-Date
    $destructionStarted = $true
    foreach ($volume in $targetVolumes) {
        & "$env:SystemRoot\System32\wsl.exe" -d Ubuntu -- docker volume rm $volume.Name
        if ($LASTEXITCODE -ne 0) { throw "Could not remove confirmed synthetic volume: $($volume.Name)" }
    }

    Invoke-Compose -Arguments @('up', '-d', 'db')
    $healthy = $false
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $containerId = (& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @('ps', '-q', 'db'))).Trim()
        if ($LASTEXITCODE -eq 0 -and $containerId) {
            $state = (& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu -- docker inspect --format '{{.State.Health.Status}}' $containerId).Trim()
            if ($LASTEXITCODE -eq 0 -and $state -eq 'healthy') { $healthy = $true; break }
        }
        Start-Sleep -Seconds 1
    }
    if (-not $healthy) { throw 'Fresh MariaDB did not become healthy for the restore drill.' }

    Invoke-Compose -Arguments @(
        '--profile', 'ops', 'run', '--rm',
        '-e', 'CLASS_ARCHIVE_RESTORE_DRILL=true',
        '-e', 'CLASS_ARCHIVE_RESTORE_CONFIRM=RESTORE_SYNTHETIC_FIXTURE_ONLY',
        '-e', "CLASS_ARCHIVE_RESTORE_BUNDLE=$bundle",
        'restore'
    )
    Invoke-Compose -Arguments @('up', '-d', 'piwigo')
    $httpReady = $false
    for ($attempt = 1; $attempt -le 90; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8090/' -TimeoutSec 3
            if ($response.StatusCode -eq 200) { $httpReady = $true; break }
        }
        catch { }
        Start-Sleep -Seconds 1
    }
    if (-not $httpReady) { throw 'Piwigo did not return localhost HTTP 200 after restore.' }

    # A first 200 can arrive while the image is still completing its lifecycle
    # hook. Require Docker's own health contract as well so the subsequent
    # media and browser-facing regression suite never races the restored
    # persistent-script normalization.
    $piwigoHealthy = $false
    for ($attempt = 1; $attempt -le 60; $attempt++) {
        $containerId = (& "$env:SystemRoot\System32\wsl.exe" @($composeBase + @('ps', '-q', 'piwigo'))).Trim()
        if ($LASTEXITCODE -eq 0 -and $containerId) {
            $state = (& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu -- docker inspect --format '{{.State.Health.Status}}' $containerId).Trim()
            if ($LASTEXITCODE -eq 0 -and $state -eq 'healthy') { $piwigoHealthy = $true; break }
        }
        Start-Sleep -Seconds 1
    }
    if (-not $piwigoHealthy) { throw 'Piwigo did not become healthy after restore.' }

    # The SQL snapshot deliberately contains projection DDL but no cache rows.
    # Rebuild deterministically from restored Piwigo/Class Archive truth before
    # any Gateway/browser regression consumes the catalog or aggregates.
    $projectionRebuild = Invoke-ReadProjectionRebuild
    [void](Save-JsonArtifact -Name 'read-projection-rebuild.json' -Value $projectionRebuild)

    # Derivatives are intentionally excluded from the business backup, but a
    # restored product must not return transient 503s while each first viewer
    # request regenerates cache entries. Rebuild the six approved Piwigo
    # product profiles plus Piwigo Core's square filmstrip profile as bounded
    # maintenance work before any HTTP regression runs.
    $derivativeWarmup = Invoke-DerivativeWarmup
    [void](Save-JsonArtifact -Name 'derivative-warmup.json' -Value $derivativeWarmup)

    Restore-ImmichOriginalMounts -State $immichMountState

    Invoke-Dev 'baseline-verify'
    $after = Get-RestoreFixture
    Assert-CanonicalFixture -Fixture $after
    [void](Save-JsonArtifact -Name 'after.json' -Value $after)
    if ($before.fixture_sha256 -ne $after.fixture_sha256) {
        throw 'Restored state fingerprint differs from the pre-backup synthetic fixture.'
    }
    $rtoSeconds = [Math]::Max(0, [int]((Get-Date) - $serviceRtoStart).TotalSeconds)
    Invoke-Compose -Arguments @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/write-backup-restore-evidence.php',
        "--bundle=$bundle",
        "--fixture-sha256=$($after.fixture_sha256)",
        "--rto-seconds=$rtoSeconds",
        "--projection-count=$([int]$projectionRebuild.count)",
        "--aggregate-count=$([int]$projectionRebuild.active_aggregate_count)"
    )
    Invoke-DevWithEvidence -Action 'test-phase0' -ArtifactName 'phase0-after-restore.log'
    Invoke-DevWithEvidence -Action 'test-phase1' -ArtifactName 'phase1-after-restore.log'
    # The HTTP suites deliberately exercise invalidation and fail-closed
    # reads. Their fixture cleanup restores business truth, but not a fresh
    # materialized projection. Rebuild once more so a successful recovery
    # drill leaves the public synthetic instance in its usable, ACTIVE state
    # instead of making the next maintenance run fail closed.
    $postRegressionProjectionRebuild = Invoke-ReadProjectionRebuild
    [void](Save-JsonArtifact -Name 'post-regression-read-projection-rebuild.json' -Value $postRegressionProjectionRebuild)
    $result = [ordered]@{
        backup_restore = 'PASS'
        bundle = $bundle
        fixture_sha256 = $after.fixture_sha256
        rto_seconds = $rtoSeconds
        baseline = '72/72/8'
        photo_catalog_projection = 'ACTIVE/72'
        aggregate_projections = 'TIMELINE,ALBUMS,PEOPLE,MEMORIES,SPOTLIGHT=ACTIVE'
        derivative_profiles = 'square,thumbnail,xsmall,small,medium,large,preview=READY'
        derivative_checked = [int]$derivativeWarmup.checked
        derivative_generated = [int]$derivativeWarmup.generated
        derivative_cached = [int]$derivativeWarmup.cached
        phase0 = 'PASS'
        phase1 = 'PASS'
        post_regression_projection = 'ACTIVE/72+5'
    }
    [void](Save-JsonArtifact -Name 'result.json' -Value $result)
    Write-Output 'BACKUP_RESTORE=PASS'
    Write-Output "RESTORE_RTO_SECONDS=$rtoSeconds"
}
catch {
    $failure = [ordered]@{ backup_restore = 'FAILED'; message = $_.Exception.Message; destruction_started = $destructionStarted }
    [void](Save-JsonArtifact -Name 'result.json' -Value $failure)
    if (-not $destructionStarted) {
        try { Invoke-Compose -Arguments @('up', '-d', 'db', 'piwigo') } catch { }
        try { Restore-ImmichOriginalMounts -State $immichMountState } catch { }
    }
    throw
}
