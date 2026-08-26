[CmdletBinding()]
param()

# Real localhost HTTP proof for archive-date precision. It temporarily changes
# only five known synthetic HERITAGE metadata rows, restores their exact prior
# rows in finally, and never changes a physical original or its association.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$spikeEnvPath = Join-Path $projectRoot 'infra\immich-spike\.env'
$fixturePassword = $null
$run = $null
$prepared = $false
$script:ArchiveTimelineStage = 'startup'

function New-Hex { $b = New-Object byte[] 8; $r = [Security.Cryptography.RandomNumberGenerator]::Create(); try { $r.GetBytes($b) } finally { $r.Dispose() }; return (($b | ForEach-Object { $_.ToString('x2') }) -join '') }
function New-Secret { $b = New-Object byte[] 32; $r = [Security.Cryptography.RandomNumberGenerator]::Create(); try { $r.GetBytes($b) } finally { $r.Dispose() }; return 'Tlr' + (($b | ForEach-Object { $_.ToString('x2') }) -join '') }
function Read-Env([string]$path) { $v=@{}; foreach($line in [IO.File]::ReadAllLines($path)){ $s=$line.Trim(); if($s -and -not $s.StartsWith('#')){ $i=$s.IndexOf('='); if($i -gt 0){$v[$s.Substring(0,$i)]=$s.Substring($i+1)} } }; return $v }
function Invoke-Docker([string[]]$arguments) {
    # Piwigo may emit a non-fatal PHP warning on stderr during bootstrap.  Do
    # not let PowerShell 5.1 convert that diagnostic into an exception before
    # we can evaluate the actual process exit status.
    $previousErrorActionPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& wsl.exe @arguments 2>&1)
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
    if ($exitCode -ne 0) {
        throw ("archive_timeline_runtime_docker_failed_" + $script:ArchiveTimelineStage + "_exit_" + $exitCode)
    }
    return $output
}

function Invoke-ProjectionRebuild {
    $output = Invoke-Docker ($compose + @(
        'exec','-T','--user','nginx','piwigo','php',
        '/workspace/infra/scripts/rebuild-photo-read-projection.php','--scope=all','--json'
    ))
    try { $result = ("$output" | ConvertFrom-Json -ErrorAction Stop) } catch { throw 'archive_timeline_runtime_projection_rebuild_invalid' }
    if ([string]$result.result -ne 'PASS') { throw 'archive_timeline_runtime_projection_rebuild_failed' }
}

if(-not(Test-Path -LiteralPath $envPath) -or -not(Test-Path -LiteralPath $spikeEnvPath)){throw 'archive_timeline_runtime_missing_local_environment'}
$settings=Read-Env $envPath
$port=[int]$settings['CLASS_ARCHIVE_HTTP_PORT']
if($port -lt 1){throw 'archive_timeline_runtime_port_invalid'}
$base=[Uri]("http://127.0.0.1:$port/")
$compat=[Uri]'http://127.0.0.1:8091/'
$ws=[Uri]::new($base,'ws.php?format=json')
$compose=@('-d','Ubuntu','--cd',$projectRoot,'--','docker','compose','--env-file','.env.piwigo','-f','infra/docker-compose.yml')
$spike=@('-d','Ubuntu','--cd',$projectRoot,'--','docker','compose','--project-directory','infra/immich-spike','--env-file','infra/immich-spike/.env','-f','infra/immich-spike/docker-compose.yml','--profile','immich-web-compat')

try {
    $run=New-Hex
    $fixturePassword=New-Secret
    $script:ArchiveTimelineStage = 'fixture_provision'
    $ready=Invoke-Docker ($compose+@('exec','-T','--user','nginx','-e',("CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword"),'piwigo','php','/workspace/tests/fixtures/provision-access-users.php'))
    if('ACCESS_FIXTURES_READY' -notin $ready){throw 'archive_timeline_runtime_fixture_provision_failed'}
    $script:ArchiveTimelineStage = 'fixture_prepare'
    $preparedOutput=Invoke-Docker ($compose+@('exec','-T','--user','nginx','-e','CLASS_ARCHIVE_ALLOW_TIMELINE_RUNTIME_FIXTURE=1','piwigo','php','/workspace/tests/phase2/archive-timeline-runtime-fixture.php','prepare',$run))
    if(("$preparedOutput") -notmatch 'ARCHIVE_TIMELINE_RUNTIME_FIXTURE=READY'){throw 'archive_timeline_runtime_prepare_failed'}
    $prepared=$true
    # This fixture writes directly for deterministic fault injection. Product
    # writes use the incremental mutation boundary; a direct test write must
    # explicitly materialize the durable read model before GET is exercised.
    $script:ArchiveTimelineStage = 'projection_rebuild'
    Invoke-ProjectionRebuild
    $script:ArchiveTimelineStage = 'compat_recreate'
    [void](Invoke-Docker ($spike+@('up','-d','--force-recreate','immich-web-compat')))
    for($i=0;$i -lt 30;$i++){ $health=(wsl.exe -d Ubuntu --exec docker inspect --format '{{.State.Health.Status}}' 'class-archive-immich-spike-immich-web-compat-1').Trim(); if($health -eq 'healthy'){break}; Start-Sleep -Seconds 1 }
    if($health -ne 'healthy'){throw 'archive_timeline_runtime_bff_not_healthy'}
    $session=[Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $login=Invoke-RestMethod -Uri $ws -Method Post -Body @{method='pwg.session.login';username='fixture-family';password=$fixturePassword} -WebSession $session -TimeoutSec 30
    if($login.stat -ne 'ok' -or -not [bool]$login.result){throw 'archive_timeline_runtime_family_login_failed'}
    $response=Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($compat,'api/class-archive/timeline')) -WebSession $session -TimeoutSec 30
    $cacheControl = [string]$response.Headers['Cache-Control']
    $privateRevalidation = $cacheControl -like '*private*' -and $cacheControl -like '*no-cache*' `
        -and $cacheControl -like '*must-revalidate*' -and $cacheControl -notlike '*public*'
    if($response.StatusCode -ne 200 -or (-not ($cacheControl -like '*no-store*') -and -not $privateRevalidation)){
        throw 'archive_timeline_runtime_projection_http_invalid'
    }
    $payload=$response.Content|ConvertFrom-Json -ErrorAction Stop
    $groups=@($payload.groups)
    $labels=@($groups|ForEach-Object{[string]$_.label})
    foreach($label in @('2012年06月','2012年09月','2011年','合成秋季运动会','日期未知')){if($labels -notcontains $label){throw "archive_timeline_runtime_missing_$label"}}
    $items=@($groups|ForEach-Object{@($_.items)})
    foreach($item in $items){if([string]$item.archive_date.precision -match '^(EXACT|DAY|MONTH|TERM|YEAR|EVENT_ONLY|UNKNOWN)$' -or [string]$item.archive_date.source -match '^(ARCHIVE_CONFIRMED|EVENT_INFERENCE|EXIF_TRUSTED|UNKNOWN)$'){throw 'archive_timeline_runtime_raw_enum_leak'}}
    if(@($items|Where-Object { [string]$_.archive_date.source -eq '已核验 EXIF 日期' -and [string]$_.archive_date.label -eq '2011年' }).Count -ne 1){throw 'archive_timeline_runtime_exif_projection_missing'}
    Write-Output 'ARCHIVE_TIMELINE_RUNTIME=PASS evidence=RUNTIME_TESTED'
    Write-Output 'DATE_PRECISION=PASS evidence=RUNTIME_TESTED'
    Write-Output 'EVENT_TIMELINE=PASS evidence=RUNTIME_TESTED'
    Write-Output 'EXIF_TIMELINE_SOURCE=PASS evidence=RUNTIME_TESTED'
}
finally {
    $cleanupFailure = $null
    if($prepared -and $run -match '^[a-f0-9]{16}$'){
        try {
            $script:ArchiveTimelineStage = 'fixture_cleanup'
            [void](Invoke-Docker ($compose + @(
                'exec','-T','--user','nginx','-e','CLASS_ARCHIVE_ALLOW_TIMELINE_RUNTIME_FIXTURE=1',
                'piwigo','php','/workspace/tests/phase2/archive-timeline-runtime-fixture.php','cleanup',$run
            )))
            $script:ArchiveTimelineStage = 'cleanup_projection_rebuild'
            Invoke-ProjectionRebuild
        } catch {
            $cleanupFailure = 'archive_timeline_runtime_cleanup_failed'
        }
    }
    if($fixturePassword){
        $fixturePassword=$null
        # A fresh random value, rather than clearing fixture users, invalidates
        # every password used by this HTTP test without persisting a secret.
        $rotate=New-Secret
        try {
            $script:ArchiveTimelineStage = 'fixture_password_rotate'
            [void](Invoke-Docker ($compose + @(
                'exec','-T','--user','nginx','-e',("CLASS_ARCHIVE_FIXTURE_PASSWORD=$rotate"),
                'piwigo','php','/workspace/tests/fixtures/provision-access-users.php'
            )))
        } catch {
            if ($null -eq $cleanupFailure) { $cleanupFailure = 'archive_timeline_runtime_password_rotation_failed' }
        } finally {
            $rotate=$null
        }
    }
    if ($null -ne $cleanupFailure) { throw $cleanupFailure }
}
