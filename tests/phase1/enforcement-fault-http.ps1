[CmdletBinding()]
param()

# Real Piwigo + MariaDB fault injection: an explicit enforcement=false value
# without the trusted maintenance marker must block every HTTP actor/surface.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:assertions = 0

function Assert-True {
    param([Parameter(Mandatory = $true)][bool]$Condition, [Parameter(Mandatory = $true)][string]$Message)
    $script:assertions++
    if (-not $Condition) { throw "ENFORCEMENT_FAULT_HTTP: $Message" }
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid .env.piwigo syntax.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function Require-Setting {
    param([hashtable]$Settings, [string]$Key)
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Key])) {
        throw "Missing ignored local setting $Key."
    }
    return [string]$Settings[$Key]
}

function New-TransientSecret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-WS {
    param([Uri]$Uri, [Microsoft.PowerShell.Commands.WebRequestSession]$Session, [hashtable]$Body)
    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function Login-Actor {
    param([Uri]$Uri, [string]$Username, [string]$Password)
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $result = Invoke-WS -Uri $Uri -Session $session -Body @{
        method = 'pwg.session.login'; username = $Username; password = $Password
    }
    Assert-True ($result.stat -eq 'ok' -and [bool]$result.result) "Baseline login failed for $Username."
    return $session
}

function Invoke-Http {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-EnforcementFault-Regression/1.0'
    $request.CookieContainer = if ($null -ne $Session) {
        $Session.Cookies
    } else {
        [Net.CookieContainer]::new()
    }

    $response = $null
    try { $response = [Net.HttpWebResponse]$request.GetResponse() }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        $stream = $response.GetResponseStream()
        $reader = [IO.StreamReader]::new($stream, [Text.Encoding]::UTF8)
        try {
            $buffer = New-Object char[] 513
            $read = $reader.ReadBlock($buffer, 0, $buffer.Length)
            $text = if ($read -gt 0) { -join $buffer[0..($read - 1)] } else { '' }
        } finally {
            $reader.Dispose()
        }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            Text = $text
            Truncated = $read -gt 512
        }
    } finally {
        $response.Dispose()
    }
}

function Assert-FaultDenied {
    param($Response, [Parameter(Mandatory = $true)][string]$Label)
    Assert-True ($Response.Status -eq 503) "$Label was not HTTP 503."
    Assert-True ($Response.ContentType -notlike 'image/*') "$Label returned an image MIME type."
    Assert-True (
        $Response.CacheControl -like '*no-store*' -or $Response.CacheControl -like '*no-cache*'
    ) "$Label returned a cacheable failure."
    Assert-True (-not $Response.Truncated -and $Response.Text.Length -le 256) "$Label exposed an oversized response."
    Assert-True (
        $Response.Text -eq 'Access denied.' `
        -or $Response.Text -eq '{"stat":"fail","err":403,"message":"Access denied"}' `
        -or $Response.Text -eq "Media temporarily unavailable.`n"
    ) "$Label returned a non-generic failure body."
}

function Invoke-FaultFixture {
    param([string]$Action, [string]$RunId)
    $output = @(& wsl.exe @($script:compose + @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/tests/phase1/enforcement-fault-fixture.php', $Action, $RunId
    )) 2>&1)
    if ($LASTEXITCODE -ne 0 -or $output.Count -ne 1) {
        throw "Enforcement fault fixture action failed: $Action"
    }
    return ($output[0] | ConvertFrom-Json)
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting $settings 'PIWIGO_ADMIN_USERNAME'
$baseUri = [Uri]("http://127.0.0.1:$port/")
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$adminUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-dashboard')
$runId = ([Guid]::NewGuid().ToString('N').Substring(0, 12)).ToLowerInvariant()
$fixturePassword = New-TransientSecret
$script:compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$provisionOutput = @(& wsl.exe @($script:compose + @(
    'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
    'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
)) 2>&1)
if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin $provisionOutput) {
    throw 'Synthetic enforcement-fault fixture provisioning failed.'
}

$resolveOutput = @(& wsl.exe @($script:compose + @(
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/tests/phase1/maintenance-gate-fixture.php', 'resolve', $runId
)) 2>&1)
if ($LASTEXITCODE -ne 0 -or $resolveOutput.Count -ne 1) {
    throw 'Synthetic media resolution failed.'
}
$mediaFixture = $resolveOutput[0] | ConvertFrom-Json
$originalPath = [string]$mediaFixture.original_path
if ($originalPath -notmatch '\Aupload/[A-Za-z0-9_./-]+\z' -or $originalPath.Contains('..')) {
    throw 'Synthetic original path was unsafe.'
}
$originalUri = [Uri]::new($baseUri, $originalPath)

$actors = [ordered]@{
    CLASSMATE = @('fixture-classmate', $fixturePassword)
    TEACHER = @('fixture-teacher', $fixturePassword)
    FAMILY = @('fixture-family', $fixturePassword)
    ANONYMOUS = @('fixture-anonymous', $fixturePassword)
}
$sessions = @{}
foreach ($actor in $actors.Keys) {
    $surfaces = @('root', 'ws', 'media')
    foreach ($surface in $surfaces) {
        $sessions["$actor/$surface"] = Login-Actor $wsUri $actors[$actor][0] $actors[$actor][1]
    }
}
$adminLeases = [Collections.Generic.List[object]]::new()
$primaryError = $null
$cleanupFailures = [Collections.Generic.List[string]]::new()

try {
    foreach ($surface in @('root', 'ws', 'media', 'admin')) {
        $lease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:compose -AdminUsername $adminUsername
        $adminLeases.Add($lease)
        $sessions["SYSTEM_ADMIN/$surface"] = $lease.Session
    }
    $baseline = Invoke-FaultFixture 'status' $runId
    Assert-True ($baseline.state -eq 'true') 'Baseline enforcement was not true.'
    Assert-True ($baseline.marker -eq 'ABSENT') 'Baseline maintenance marker was present.'
    Assert-True ($baseline.owner -eq 'ABSENT') 'Baseline fault owner was present.'

    $opened = Invoke-FaultFixture 'begin' $runId
    Assert-True ($opened.state -eq 'false') 'Fault injection did not set enforcement=false.'
    Assert-True ($opened.marker -eq 'ABSENT') 'Fault injection created a production maintenance marker.'
    Assert-True ($opened.owner -eq 'EXACT') 'Fault recovery owner was not exact.'

    $guestSession = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    Assert-FaultDenied (Invoke-Http $baseUri $guestSession) 'GUEST/root'
    Assert-FaultDenied (Invoke-Http $wsUri ([Microsoft.PowerShell.Commands.WebRequestSession]::new())) 'GUEST/ws'
    Assert-FaultDenied (Invoke-Http $originalUri ([Microsoft.PowerShell.Commands.WebRequestSession]::new())) 'GUEST/media'

    foreach ($actor in $actors.Keys) {
        Assert-FaultDenied (Invoke-Http $baseUri $sessions["$actor/root"]) "$actor/root"
        Assert-FaultDenied (Invoke-Http $wsUri $sessions["$actor/ws"]) "$actor/ws"
        Assert-FaultDenied (Invoke-Http $originalUri $sessions["$actor/media"]) "$actor/media"
    }
    $systemAdminSurfaces = [ordered]@{ root = $baseUri; ws = $wsUri; media = $originalUri }
    foreach ($surface in $systemAdminSurfaces.Keys) {
        Assert-FaultDenied (Invoke-Http $systemAdminSurfaces[$surface] $sessions["SYSTEM_ADMIN/$surface"]) "SYSTEM_ADMIN/$surface"
    }
    Assert-FaultDenied (Invoke-Http $adminUri $sessions['SYSTEM_ADMIN/admin']) 'SYSTEM_ADMIN/admin'
}
catch {
    # Preserve the first suite failure. Cleanup failures are reported separately
    # after every acquired lease has received an exact-revoke attempt.
    $primaryError = $_
}
finally {
    try {
        # Inspect durable ownership instead of trusting that begin returned. This
        # also recovers when enforcement was disabled but transport/JSON handling
        # failed before the runner observed the response.
        $recoveryStatus = Invoke-FaultFixture 'status' $runId
        if ($recoveryStatus.owner -eq 'EXACT') {
            $restored = Invoke-FaultFixture 'restore' $runId
            Assert-True ($restored.state -eq 'true') 'Recovery did not restore enforcement=true.'
            Assert-True ($restored.marker -eq 'ABSENT') 'Recovery left a production maintenance marker.'
            Assert-True ($restored.owner -eq 'ABSENT') 'Recovery left its database run owner.'
        } elseif ($recoveryStatus.owner -eq 'ABSENT') {
            Assert-True ($recoveryStatus.state -eq 'true') 'No owned fault remained, but enforcement was not true.'
            Assert-True ($recoveryStatus.marker -eq 'ABSENT') 'No owned fault remained, but maintenance was active.'
        } else {
            throw 'Recovery refused unknown or foreign database fault ownership.'
        }
    }
    catch {
        $cleanupFailures.Add("enforcement recovery failed: $($_.Exception.Message)")
    }
    finally {
        # Recovery/status failure cannot skip a later lease, and one failed
        # revocation cannot prevent an exact-revoke attempt for the rest.
        $leaseIndex = 0
        foreach ($lease in $adminLeases) {
            $leaseIndex++
            try {
                Remove-ClassArchiveSystemAdminSession -Lease $lease
            }
            catch {
                $cleanupFailures.Add("SYSTEM_ADMIN lease $leaseIndex exact revocation failed: $($_.Exception.Message)")
            }
        }
        $fixturePassword = $null
    }
}

if ($null -ne $primaryError) {
    foreach ($cleanupFailure in $cleanupFailures) {
        Write-Output "ENFORCEMENT_FAULT_HTTP_CLEANUP_FAILURE: $cleanupFailure"
    }
    $PSCmdlet.ThrowTerminatingError($primaryError)
}
if ($cleanupFailures.Count -gt 0) {
    throw "ENFORCEMENT_FAULT_HTTP cleanup failed: $($cleanupFailures -join '; ')"
}

Write-Output "CLASS_IDENTITY_ENFORCEMENT_FAULT_HTTP=PASS assertions=$script:assertions"
Write-Output 'DB_FALSE_WITHOUT_MARKER=ALL_HTTP_DENY'
