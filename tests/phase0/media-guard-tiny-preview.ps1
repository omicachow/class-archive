[CmdletBinding()]
param()

# Real HTTP regression for Piwigo's "source smaller than derivative" branch.
# The temporary photo is generated from pixels, contains no real person/data,
# and is physically removed with its database row and derivatives in finally.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$fixture = $null
$baselineCount = $null
$cleanupError = $null
$probeCount = 0

function Read-DotEnv([string]$Path) {
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

function Require-Setting([hashtable]$Settings, [string]$Key) {
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Settings[$Key])) {
        throw "Missing local setting: $Key"
    }
    return [string]$Settings[$Key]
}

function New-TransientPassword {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)][array]$ComposeBase,
        [Parameter(Mandatory = $true)][string]$Mode,
        [string]$RunId = '',
        [int]$ImageId = 0
    )

    $arguments = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_ALLOW_TINY_PREVIEW_FIXTURE=1',
        'piwigo', 'php', '/workspace/tests/phase0/tiny-preview-fixture.php', $Mode
    )
    if ($RunId -ne '') { $arguments += $RunId }
    if ($ImageId -gt 0) { $arguments += [string]$ImageId }
    $output = & wsl.exe @($ComposeBase + $arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "Tiny-preview fixture command failed: $Mode"
    }
    return (($output -join "`n").Trim() | ConvertFrom-Json)
}

function New-AuthenticatedSession {
    param([Uri]$WebServiceUri, [string]$Username, [string]$Password)

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $result = Invoke-RestMethod -Uri $WebServiceUri -Method Post -WebSession $session -TimeoutSec 30 -Body @{
        method = 'pwg.session.login'
        username = $Username
        password = $Password
    }
    if ($result.stat -ne 'ok' -or -not $result.result) {
        throw "Synthetic login failed for $Username"
    }
    return $session
}

function Invoke-Probe {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'HEAD', 'RANGE')][string]$Mode = 'GET'
    )

    $script:probeCount++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = if ($Mode -eq 'HEAD') { 'HEAD' } else { 'GET' }
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-TinyPreview-Regression/1.0'
    if ($Mode -eq 'RANGE') { $request.AddRange(0, 31) }

    $response = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }

    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) {
            $headers[$key.ToLowerInvariant()] = [string]$response.Headers[$key]
        }
        $memory = [IO.MemoryStream]::new()
        if ($Mode -ne 'HEAD') {
            $stream = $response.GetResponseStream()
            try { $stream.CopyTo($memory) } finally { $stream.Dispose() }
        }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            Headers = $headers
            Body = $memory.ToArray()
        }
    }
    finally {
        $response.Dispose()
    }
}

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Assert-PrivateMedia($Response, [string]$Label) {
    $cache = if ($Response.Headers.ContainsKey('cache-control')) { [string]$Response.Headers['cache-control'] } else { '' }
    $vary = if ($Response.Headers.ContainsKey('vary')) { [string]$Response.Headers['vary'] } else { '' }
    Assert-True ($cache -match '(?i)private' -and $cache -match '(?i)no-cache') "$Label lacks private revalidation cache policy."
    Assert-True ($vary -match '(?i)(^|,\s*)Cookie(\s*,|$)') "$Label lacks Vary: Cookie."
}

function Assert-NoCoreRedirectHeaders($Response, [string]$Label) {
    foreach ($header in @('location', 'content-location', 'request-uri', 'x-i')) {
        Assert-True (-not $Response.Headers.ContainsKey($header)) "$Label leaked Core redirect header $header."
    }
}

function Assert-AllowImage($Response, [string]$Label, [int]$ExpectedStatus = 200) {
    Assert-True ($Response.Status -eq $ExpectedStatus) "$Label expected HTTP $ExpectedStatus, got $($Response.Status)."
    Assert-True ($Response.ContentType -like 'image/*') "$Label did not return image media."
    Assert-NoCoreRedirectHeaders $Response $Label
    Assert-PrivateMedia $Response $Label
}

function Assert-Deny($Response, [string]$Label, [int[]]$Expected = @(403)) {
    Assert-True ($Response.Status -in $Expected) "$Label expected denial $($Expected -join '/'), got $($Response.Status)."
    Assert-True ($Response.ContentType -notlike 'image/*') "$Label returned image bytes on denial."
    Assert-NoCoreRedirectHeaders $Response $Label
    Assert-PrivateMedia $Response $Label
}

function Get-Sha256([byte[]]$Bytes) {
    $algorithm = [Security.Cryptography.SHA256]::Create()
    try {
        return ([BitConverter]::ToString($algorithm.ComputeHash($Bytes))).Replace('-', '').ToLowerInvariant()
    }
    finally {
        $algorithm.Dispose()
    }
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv $envPath
$port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting $settings 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^\d{1,5}$') { throw 'Invalid localhost port.' }

$baseUri = [Uri]("http://127.0.0.1:$port/")
$webServiceUri = [Uri]::new($baseUri, 'ws.php?format=json')
$composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)
$runBytes = New-Object byte[] 8
$runGenerator = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $runGenerator.GetBytes($runBytes) } finally { $runGenerator.Dispose() }
$runId = ([BitConverter]::ToString($runBytes)).Replace('-', '').ToLowerInvariant()

$testError = $null
$adminLease = $null
try {
    $baselineCount = [int](Invoke-Fixture -ComposeBase $composeBase -Mode 'count').image_count
    $fixture = Invoke-Fixture -ComposeBase $composeBase -Mode 'create' -RunId $runId
    Assert-True ([int]$fixture.source_width -eq 160 -and [int]$fixture.source_height -eq 120) 'Tiny fixture dimensions are not 160x120.'

    $fixturePassword = New-TransientPassword
    $provisionOutput = & wsl.exe @($composeBase + @(
        'exec', '-T', '--user', 'nginx',
        '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
        'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
    ))
    if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin @($provisionOutput)) {
        throw 'Could not provision synthetic role accounts.'
    }

    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $composeBase -AdminUsername $adminUsername
    $sessions = @{
        Admin = $adminLease.Session
        Family = New-AuthenticatedSession $webServiceUri 'fixture-family' $fixturePassword
        Anonymous = New-AuthenticatedSession $webServiceUri 'fixture-anonymous' $fixturePassword
        Guest = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    }

    $imageId = [int]$fixture.image_id
    $sourceUri = [Uri]::new($baseUri, [string]$fixture.source_path)
    $derivativeUri = [Uri]::new($baseUri, 'i.php?/' + [string]$fixture.derivative_path)
    $knownDerivativeUri = [Uri]::new($baseUri, '_data/i/' + [string]$fixture.derivative_path)
    $actionPreviewUri = [Uri]::new($baseUri, "action.php?id=$imageId&part=e")
    $actionDownloadUri = [Uri]::new($baseUri, "action.php?id=$imageId&part=e&download")
    $actionTamperedDownloadUri = [Uri]::new($baseUri, "action.php?id=$imageId&part=e&download=0")

    # First miss: the explicit derivative enters Core i.php, receives its exact
    # No-change redirect internally, is re-authorized, re-encoded and returned
    # as HTTP 200 without exposing the source Location.
    $firstMiss = Invoke-Probe $derivativeUri $sessions.Family
    Assert-AllowImage $firstMiss 'family/explicit-derivative/cache-miss'
    $firstHash = Get-Sha256 $firstMiss.Body
    Assert-True ($firstHash -ne [string]$fixture.source_sha256) 'Safe preview bytes are identical to the archived original.'
    $inspection = Invoke-Fixture -ComposeBase $composeBase -Mode 'inspect' -RunId $runId -ImageId $imageId
    Assert-True ([string]$inspection.mode -eq '660') "Safe preview mode is $($inspection.mode), expected 660."
    Assert-True ([int]$inspection.derivative_width -eq [int]$inspection.source_width) 'Safe preview width changed unexpectedly.'
    Assert-True ([int]$inspection.derivative_height -eq [int]$inspection.source_height) 'Safe preview height changed unexpectedly.'
    Assert-True ([string]$inspection.derivative_sha256 -eq $firstHash) 'HTTP bytes differ from the atomically published derivative.'

    # Force a second cache miss to prove Core's normal no-download action URL
    # has SAFE_PREVIEW semantics and does not inherit original permission.
    $null = Invoke-Fixture -ComposeBase $composeBase -Mode 'purge' -RunId $runId -ImageId $imageId
    $actionMiss = Invoke-Probe $actionPreviewUri $sessions.Family
    Assert-AllowImage $actionMiss 'family/action-safe-preview/cache-miss'
    Assert-True ((Get-Sha256 $actionMiss.Body) -ne [string]$fixture.source_sha256) 'Action preview returned archived original bytes.'
    Assert-True (-not $actionMiss.Headers.ContainsKey('content-disposition')) 'Action preview was marked as an original download.'

    $anonymousPreview = Invoke-Probe $actionPreviewUri $sessions.Anonymous
    Assert-AllowImage $anonymousPreview 'anonymous/action-safe-preview/cache-hit'
    $guestPreview = Invoke-Probe $actionPreviewUri $sessions.Guest
    Assert-Deny $guestPreview 'guest/action-safe-preview'

    foreach ($entry in @(
        @{ Label = 'family/known-source'; Uri = $sourceUri; Session = $sessions.Family },
        @{ Label = 'anonymous/known-source'; Uri = $sourceUri; Session = $sessions.Anonymous },
        @{ Label = 'family/explicit-download'; Uri = $actionDownloadUri; Session = $sessions.Family },
        @{ Label = 'anonymous/explicit-download'; Uri = $actionDownloadUri; Session = $sessions.Anonymous },
        @{ Label = 'family/download-query-tamper'; Uri = $actionTamperedDownloadUri; Session = $sessions.Family }
    )) {
        Assert-Deny (Invoke-Probe $entry.Uri $entry.Session) $entry.Label
    }

    $adminOriginal = Invoke-Probe $actionDownloadUri $sessions.Admin
    Assert-AllowImage $adminOriginal 'admin/explicit-download'
    Assert-True ((Get-Sha256 $adminOriginal.Body) -eq [string]$fixture.source_sha256) 'Authorized Admin original bytes changed.'

    $knownFamily = Invoke-Probe $knownDerivativeUri $sessions.Family
    Assert-AllowImage $knownFamily 'family/known-safe-preview'
    Assert-Deny (Invoke-Probe $knownDerivativeUri $sessions.Guest) 'guest/known-safe-preview'

    $head = Invoke-Probe $actionPreviewUri $sessions.Family 'HEAD'
    Assert-AllowImage $head 'family/action-safe-preview/HEAD'
    Assert-True ($head.Body.Length -eq 0) 'HEAD returned a response body.'
    $range = Invoke-Probe $actionPreviewUri $sessions.Family 'RANGE'
    Assert-AllowImage $range 'family/action-safe-preview/RANGE' 206
    Assert-True ($range.Body.Length -eq 32) "Range returned $($range.Body.Length) bytes, expected 32."

    $fallbackPublic = [Uri]::new($baseUri, 'plugins/ClassArchivePolicy/identity-derivative-fallback.php')
    $fallbackDirect = Invoke-Probe $fallbackPublic $sessions.Admin
    Assert-True ($fallbackDirect.Status -eq 404) "admin/direct-fallback-controller expected HTTP 404, got $($fallbackDirect.Status)."
    Assert-True ($fallbackDirect.ContentType -notlike 'image/*') 'admin/direct-fallback-controller returned image bytes.'
    Assert-NoCoreRedirectHeaders $fallbackDirect 'admin/direct-fallback-controller'

    $logout = Invoke-RestMethod -Uri $webServiceUri -Method Post -WebSession $sessions.Family -TimeoutSec 30 -Body @{
        method = 'pwg.session.logout'
    }
    Assert-True ($logout.stat -eq 'ok') 'Family logout failed.'
    Assert-Deny (Invoke-Probe $actionPreviewUri $sessions.Family) 'family/logged-out-known-safe-preview'
}
catch {
    $testError = $_
}
finally {
    if ($null -ne $adminLease) {
        try { Remove-ClassArchiveSystemAdminSession -Lease $adminLease }
        catch { if ($null -eq $cleanupError) { $cleanupError = $_ } }
    }
    if ($null -ne $fixture) {
        try {
            $deleteResult = Invoke-Fixture -ComposeBase $composeBase -Mode 'delete' -RunId $runId -ImageId ([int]$fixture.image_id)
            if (
                (-not [bool]$deleteResult.deleted) -or
                (-not [bool]$deleteResult.original_deleted) -or
                (-not [bool]$deleteResult.derivative_deleted)
            ) {
                throw 'Synthetic fixture cleanup did not confirm physical original and derivative deletion.'
            }
        }
        catch {
            $cleanupError = $_
        }
    }
    if ($null -ne $baselineCount) {
        try {
            $afterCount = [int](Invoke-Fixture -ComposeBase $composeBase -Mode 'count').image_count
            if ($afterCount -ne $baselineCount) {
                throw "Synthetic fixture cleanup changed image count: before=$baselineCount after=$afterCount"
            }
        }
        catch {
            if ($null -eq $cleanupError) { $cleanupError = $_ }
        }
    }
    $fixturePassword = $null
}

if ($null -ne $cleanupError) {
    Write-Error "TINY_PREVIEW_CLEANUP=FAIL $($cleanupError.Exception.Message)"
    exit 2
}
if ($null -ne $testError) {
    Write-Error "TINY_PREVIEW_HTTP=FAIL $($testError.Exception.Message)"
    exit 1
}

Write-Output 'TINY_PREVIEW_HTTP=PASS'
Write-Output "HTTP_PROBES=$probeCount"
Write-Output 'ACTION_NO_DOWNLOAD=SAFE_PREVIEW'
Write-Output 'ACTION_WITH_DOWNLOAD=ORIGINAL'
Write-Output 'CORE_NO_CHANGE_REDIRECT=INTERNAL_REENCODE'
Write-Output 'ORIGINAL_BYTES_TO_FAMILY_ANONYMOUS=DENY'
Write-Output 'SYNTHETIC_FIXTURE_CLEANUP=PASS'
Write-Output 'SYNTHETIC_ORIGINAL_PHYSICALLY_REMOVED=PASS'
