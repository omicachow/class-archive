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

function Invoke-Dev {
    param([Parameter(Mandatory = $true)][string]$Action)
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $projectRoot 'infra\scripts\dev.ps1') $Action
    if ($LASTEXITCODE -ne 0) {
        throw "Project verification failed: $Action"
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

function Save-JsonArtifact {
    param([Parameter(Mandatory = $true)][string]$Name, [Parameter(Mandatory = $true)]$Value)
    $path = Join-Path $workRoot $Name
    [IO.File]::WriteAllText($path, ($Value | ConvertTo-Json -Depth 20), [Text.UTF8Encoding]::new($false))
    return $path
}

function Assert-CanonicalFixture {
    param([Parameter(Mandatory = $true)]$Fixture)
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
        "--rto-seconds=$rtoSeconds"
    )
    Invoke-Dev 'test-phase0'
    Invoke-Dev 'test-phase1'
    $result = [ordered]@{
        backup_restore = 'PASS'
        bundle = $bundle
        fixture_sha256 = $after.fixture_sha256
        rto_seconds = $rtoSeconds
        baseline = '72/72/8'
        phase0 = 'PASS'
        phase1 = 'PASS'
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
    }
    throw
}
