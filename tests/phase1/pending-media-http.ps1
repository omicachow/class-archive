[CmdletBinding()]
param()

# Real HTTP release gate for Community moderation media. The Community plugin
# remains inactive and no image is uploaded or created. A reversible fixture
# binds one existing synthetic HERITAGE image to Community moderation state,
# raises all four synthetic Seat accounts to privacy level 16, and restores
# passwords, levels, image level, pending rows, and the 72-image baseline.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:probeCount = 0

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
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$Key
    )
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Settings[$Key])) {
        throw "Missing required local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function New-Secret {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-WS {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Body
    )
    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function Login-Role {
    param(
        [Parameter(Mandatory = $true)][Uri]$WsUri,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$Role
    )
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS $WsUri $session @{
        method = 'pwg.session.login'
        username = $Username
        password = $Password
    }
    if ($response.stat -ne 'ok' -or -not $response.result) {
        throw "Synthetic login was rejected for $Role."
    }
    return $session
}

function Logout-BestEffort {
    param(
        [Parameter(Mandatory = $true)][Uri]$WsUri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )
    try {
        Invoke-WS $WsUri $Session @{ method = 'pwg.session.logout' } | Out-Null
    }
    catch {
        # Exact database restoration does not depend on logout succeeding.
    }
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId,
        [Parameter(Mandatory = $true)][array]$ComposeBase,
        [string]$Password = ''
    )
    $arguments = $ComposeBase + @(
        'exec', '-T', '--user', 'nginx',
        '-e', "CLASS_ARCHIVE_PENDING_ACTION=$Action",
        '-e', "CLASS_ARCHIVE_PENDING_RUN_ID=$RunId"
    )
    if ($Action -eq 'prepare') {
        $arguments += @('-e', "CLASS_ARCHIVE_PENDING_PASSWORD=$Password")
    }
    $arguments += @('piwigo', 'php', '/workspace/tests/phase1/pending-media-fixture.php')
    $output = @(& wsl.exe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "Pending-media fixture action failed: $Action."
    }
    $line = @($output | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) }) | Select-Object -Last 1
    if ($null -eq $line) { throw "Pending-media fixture returned no result: $Action." }
    try { return ([string]$line | ConvertFrom-Json) }
    catch { throw "Pending-media fixture returned invalid JSON: $Action." }
}

function Resolve-Media {
    param(
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][Uri]$WsUri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$AdminSession,
        [Parameter(Mandatory = $true)][int]$ImageId,
        [Parameter(Mandatory = $true)][string]$SourcePath
    )
    $info = Invoke-WS $WsUri $AdminSession @{
        method = 'pwg.images.getInfo'
        image_id = $ImageId
    }
    if ($info.stat -ne 'ok' -or $null -eq $info.result.derivatives) {
        throw 'Could not resolve fixture derivative URLs.'
    }
    $thumb = $null
    foreach ($name in @('thumb', 'square', 'xsmall', 'small')) {
        $property = $info.result.derivatives.PSObject.Properties[$name]
        if ($null -ne $property -and $null -ne $property.Value) {
            $url = $property.Value.PSObject.Properties['url']
            if ($null -ne $url -and -not [string]::IsNullOrWhiteSpace([string]$url.Value)) {
                $thumb = [string]$url.Value
                break
            }
        }
    }
    $preview = $null
    foreach ($name in @('medium', 'large', 'small', 'xlarge')) {
        $property = $info.result.derivatives.PSObject.Properties[$name]
        if ($null -ne $property -and $null -ne $property.Value) {
            $url = $property.Value.PSObject.Properties['url']
            if ($null -ne $url -and -not [string]::IsNullOrWhiteSpace([string]$url.Value)) {
                $preview = [string]$url.Value
                break
            }
        }
    }
    if ($null -eq $thumb -or $null -eq $preview) {
        throw 'Fixture derivative URL set is incomplete.'
    }
    $media = [ordered]@{
        Thumb = [Uri]::new($BaseUri, [Net.WebUtility]::HtmlDecode($thumb))
        Preview = [Uri]::new($BaseUri, [Net.WebUtility]::HtmlDecode($preview))
        Original = [Uri]::new($BaseUri, $SourcePath)
    }
    foreach ($entry in $media.GetEnumerator()) {
        if (
            $entry.Value.Scheme -ne $BaseUri.Scheme -or
            $entry.Value.Host -ne $BaseUri.Host -or
            $entry.Value.Port -ne $BaseUri.Port
        ) {
            throw "Fixture $($entry.Key) URL escaped loopback origin."
        }
    }
    $uniqueTargets = @($media.Values | ForEach-Object { $_.AbsoluteUri } | Select-Object -Unique)
    if ($uniqueTargets.Count -ne 3) {
        throw 'Thumb, preview, and original did not resolve to three distinct media targets.'
    }
    return $media
}

function Test-ImageMagic {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][byte[]]$Bytes)
    if ($Bytes.Length -ge 3 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xD8 -and $Bytes[2] -eq 0xFF) { return $true }
    if ($Bytes.Length -ge 8 -and $Bytes[0] -eq 0x89 -and $Bytes[1] -eq 0x50 -and $Bytes[2] -eq 0x4E -and $Bytes[3] -eq 0x47) { return $true }
    if ($Bytes.Length -ge 6) {
        $prefix = [Text.Encoding]::ASCII.GetString($Bytes, 0, 6)
        if ($prefix -eq 'GIF87a' -or $prefix -eq 'GIF89a') { return $true }
    }
    if ($Bytes.Length -ge 12 -and [Text.Encoding]::ASCII.GetString($Bytes, 0, 4) -eq 'RIFF' -and [Text.Encoding]::ASCII.GetString($Bytes, 8, 4) -eq 'WEBP') { return $true }
    return $false
}

function Invoke-Probe {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )
    $script:probeCount++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 20000
    $request.ReadWriteTimeout = 20000
    $request.UserAgent = 'ClassArchive-Pending-Media-Regression/1.0'
    $request.CachePolicy = [Net.Cache.RequestCachePolicy]::new([Net.Cache.RequestCacheLevel]::BypassCache)
    $request.Headers['Cache-Control'] = 'no-cache'
    $response = $null
    try {
        try { $response = [Net.HttpWebResponse]$request.GetResponse() }
        catch [Net.WebException] {
            if ($null -ne $_.Exception.Response) { $response = [Net.HttpWebResponse]$_.Exception.Response }
            else { throw }
        }
        $stream = $response.GetResponseStream()
        $buffer = New-Object byte[] 64
        $length = 0
        try {
            if ($null -ne $stream) { $length = $stream.Read($buffer, 0, $buffer.Length) }
        }
        finally {
            if ($null -ne $stream) { $stream.Dispose() }
        }
        $body = if ($length -gt 0) { [byte[]]$buffer[0..($length - 1)] } else { [byte[]]@() }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            Body = $body
        }
    }
    finally {
        if ($null -ne $response) { $response.Dispose() }
    }
}

function Assert-Allow {
    param($Response, [string]$Label)
    if ($Response.Status -ne 200 -or $Response.ContentType -notlike 'image/*' -or -not (Test-ImageMagic $Response.Body)) {
        throw "$Label was not an authorized image response (HTTP $($Response.Status))."
    }
}

function Assert-Deny {
    param($Response, [string]$Label)
    if ($Response.Status -ne 403 -or $Response.ContentType -like 'image/*' -or (Test-ImageMagic $Response.Body)) {
        throw "$Label did not fail closed as HTTP 403 (HTTP $($Response.Status))."
    }
}

function Assert-PublishedMatrix {
    param([hashtable]$Sessions, [System.Collections.IDictionary]$Media, [string]$Label)
    foreach ($role in @('FAMILY', 'CLASSMATE', 'TEACHER', 'ANONYMOUS', 'SYSTEM_ADMIN')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            $allow = $variant -ne 'Original' -or $role -in @('CLASSMATE', 'TEACHER', 'SYSTEM_ADMIN')
            $response = Invoke-Probe $Media[$variant] $Sessions[$role]
            if ($allow) { Assert-Allow $response "$Label/$role/$variant" }
            else { Assert-Deny $response "$Label/$role/$variant" }
        }
    }
}

function Assert-PendingMatrix {
    param([hashtable]$Sessions, [System.Collections.IDictionary]$Media, [string]$Label)
    foreach ($role in @('FAMILY', 'CLASSMATE', 'TEACHER', 'ANONYMOUS')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            Assert-Deny (Invoke-Probe $Media[$variant] $Sessions[$role]) "$Label/$role/$variant"
        }
    }
    foreach ($variant in @('Thumb', 'Preview', 'Original')) {
        Assert-Allow (Invoke-Probe $Media[$variant] $Sessions.SYSTEM_ADMIN) "$Label/SYSTEM_ADMIN/$variant"
    }
}

function Assert-UnresolvedMatrix {
    param([hashtable]$Sessions, [System.Collections.IDictionary]$Media, [string]$Label)
    foreach ($role in @('FAMILY', 'CLASSMATE', 'TEACHER', 'ANONYMOUS', 'SYSTEM_ADMIN')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            Assert-Deny (Invoke-Probe $Media[$variant] $Sessions[$role]) "$Label/$role/$variant"
        }
    }
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv $envPath
$port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting $settings 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^[0-9]{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) {
    throw 'Invalid loopback port.'
}
$baseUri = [Uri]("http://127.0.0.1:$port/")
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)
$bytes = New-Object byte[] 8
$generator = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
$runId = [BitConverter]::ToString($bytes).Replace('-', '').ToLowerInvariant()
$fixturePassword = New-Secret
$prepared = $false
$sessions = @{}
$failure = $null
$adminLease = $null

try {
    $running = @(& wsl.exe @($composeBase + @('ps', '--status', 'running', '--services')))
    if ($LASTEXITCODE -ne 0 -or 'db' -notin $running -or 'piwigo' -notin $running) {
        throw 'Piwigo and database services must already be running.'
    }
    $fixture = Invoke-Fixture 'prepare' $runId $composeBase $fixturePassword
    $prepared = $true
    if ([int]$fixture.imageCount -ne 72) { throw 'Fixture did not confirm the 72-image baseline.' }

    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $composeBase -AdminUsername $adminUsername
    $sessions.SYSTEM_ADMIN = $adminLease.Session
    $sessions.CLASSMATE = Login-Role $wsUri 'fixture-classmate' $fixturePassword 'CLASSMATE'
    $sessions.TEACHER = Login-Role $wsUri 'fixture-teacher' $fixturePassword 'TEACHER'
    $sessions.FAMILY = Login-Role $wsUri 'fixture-family' $fixturePassword 'FAMILY'
    $sessions.ANONYMOUS = Login-Role $wsUri 'fixture-anonymous' $fixturePassword 'ANONYMOUS'

    $media = Resolve-Media $baseUri $wsUri $sessions.SYSTEM_ADMIN ([int]$fixture.imageId) ([string]$fixture.sourcePath)
    Assert-PublishedMatrix $sessions $media 'no-community-row'

    Invoke-Fixture 'set_validated' $runId $composeBase | Out-Null
    Assert-PublishedMatrix $sessions $media 'validated-row-level16'

    Invoke-Fixture 'validated_to_pending' $runId $composeBase | Out-Null
    Assert-PendingMatrix $sessions $media 'moderation-pending'

    Invoke-Fixture 'set_malformed' $runId $composeBase | Out-Null
    Assert-UnresolvedMatrix $sessions $media 'malformed-state'

    Invoke-Fixture 'malformed_to_pending' $runId $composeBase | Out-Null
    Invoke-Fixture 'set_ambiguous' $runId $composeBase | Out-Null
    Assert-UnresolvedMatrix $sessions $media 'duplicate-row-ambiguity'
}
catch {
    $failure = $_.Exception.Message
}
finally {
    if ($prepared) {
        try {
            $restored = Invoke-Fixture 'restore' $runId $composeBase
            if ([string]$restored.state -ne 'RESTORED' -or [int]$restored.imageCount -ne 72) {
                throw 'Fixture did not prove exact restoration.'
            }
            $prepared = $false
        }
        catch {
            if ($null -eq $failure) { $failure = $_.Exception.Message }
        }
    }
    foreach ($session in $sessions.Values) {
        if ($null -ne $session) { Logout-BestEffort $wsUri $session }
    }
    if ($null -ne $adminLease) {
        try { Remove-ClassArchiveSystemAdminSession -Lease $adminLease }
        catch { if ($null -eq $failure) { $failure = $_.Exception.Message } }
    }
    $fixturePassword = $null
}

if ($null -ne $failure) {
    [Console]::Error.WriteLine("PENDING_MEDIA_HTTP=FAIL $failure")
    exit 1
}

Write-Output 'PENDING_MEDIA_HTTP=PASS'
Write-Output "HTTP_PROBES=$script:probeCount"
Write-Output 'COMMUNITY_INACTIVE=PASS'
Write-Output 'PENDING_SEAT_ROLES_DENY=PASS'
Write-Output 'PENDING_SYSTEM_ADMIN_ALLOW=PASS'
Write-Output 'MALFORMED_STATE_FAIL_CLOSED=PASS'
Write-Output 'DUPLICATE_STATE_FAIL_CLOSED=PASS'
Write-Output 'IMAGE_MODEL_RESTORED=72'
