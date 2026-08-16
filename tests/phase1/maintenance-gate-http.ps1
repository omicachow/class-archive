[CmdletBinding()]
param()

# Reversible production-facing check for the nginx maintenance marker used
# during ClassIdentity install/bootstrap. Authorization evidence is HTTP; the
# companion fixture only owns the exact marker and resolves one synthetic file.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:failures = [Collections.Generic.List[string]]::new()
$script:probeCount = 0
$script:composeBase = @()

function Add-Failure {
    param([Parameter(Mandatory = $true)][string]$Label, [Parameter(Mandatory = $true)][string]$Message)
    $entry = "$Label :: $Message"
    if (-not $script:failures.Contains($entry)) { $script:failures.Add($entry) }
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
    param([Parameter(Mandatory = $true)][hashtable]$Settings, [Parameter(Mandatory = $true)][string]$Key)
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Key])) {
        throw "Missing required ignored local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function New-RunId {
    $bytes = New-Object byte[] 6
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('status', 'open', 'close', 'resolve')][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId
    )
    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in $script:composeBase) { $arguments.Add($argument) }
    foreach ($argument in @(
        'exec', '-T', '--user', 'nginx', 'piwigo', 'php',
        '/workspace/tests/phase1/maintenance-gate-fixture.php', $Action, $RunId
    )) { $arguments.Add($argument) }

    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { $output = @(& wsl.exe @arguments 2>&1) }
    finally { $ErrorActionPreference = $previousErrorAction }
    if ($LASTEXITCODE -ne 0) { throw "Maintenance fixture action '$Action' failed." }
    try { return (($output -join '').Trim() | ConvertFrom-Json) }
    catch { throw "Maintenance fixture action '$Action' returned invalid JSON." }
}

function ConvertTo-FormBody {
    param([Parameter(Mandatory = $true)][hashtable]$Form)
    return (($Form.Keys | Sort-Object | ForEach-Object {
        [Net.WebUtility]::UrlEncode([string]$_) + '=' + [Net.WebUtility]::UrlEncode([string]$Form[$_])
    }) -join '&')
}

function Invoke-Http {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        [hashtable]$Form = @{},
        [ValidateRange(64, 4194304)][int]$MaxBodyBytes = 2097152
    )
    $script:probeCount++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-Maintenance-Gate-Regression/1.0'
    $request.AutomaticDecompression = [Net.DecompressionMethods]::GZip -bor [Net.DecompressionMethods]::Deflate
    if ($Method -eq 'POST') {
        $bytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-FormBody -Form $Form))
        $request.ContentType = 'application/x-www-form-urlencoded; charset=UTF-8'
        $request.ContentLength = $bytes.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($bytes, 0, $bytes.Length) } finally { $stream.Dispose() }
    }

    $response = $null
    try { $response = [Net.HttpWebResponse]$request.GetResponse() }
    catch [Net.WebException] {
        if ($null -ne $_.Exception.Response) { $response = [Net.HttpWebResponse]$_.Exception.Response }
        else { return [pscustomobject]@{ Status=0; ContentType=''; Body=[byte[]]@(); Text=''; Location=''; TransportError='no HTTP response' } }
    }
    try {
        $memory = [IO.MemoryStream]::new()
        $stream = $response.GetResponseStream()
        try {
            if ($null -ne $stream) {
                $buffer = New-Object byte[] 8192
                while ($memory.Length -lt $MaxBodyBytes) {
                    $remaining = [int]($MaxBodyBytes - $memory.Length)
                    $read = $stream.Read($buffer, 0, [Math]::Min($buffer.Length, $remaining))
                    if ($read -le 0) { break }
                    $memory.Write($buffer, 0, $read)
                }
            }
        } finally { if ($null -ne $stream) { $stream.Dispose() } }
        $body = $memory.ToArray()
        $memory.Dispose()
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            Body = $body
            Text = [Text.Encoding]::UTF8.GetString($body)
            Location = [string]$response.Headers['Location']
            TransportError = $null
        }
    } finally { $response.Dispose() }
}

function Test-ImageMagic {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][byte[]]$Bytes)
    $prefixes = @(
        [byte[]](0xFF,0xD8,0xFF),
        [byte[]](0x89,0x50,0x4E,0x47,0x0D,0x0A,0x1A,0x0A),
        [Text.Encoding]::ASCII.GetBytes('GIF87a'),
        [Text.Encoding]::ASCII.GetBytes('GIF89a')
    )
    foreach ($prefix in $prefixes) {
        if ($Bytes.Length -lt $prefix.Length) { continue }
        $matches = $true
        for ($index = 0; $index -lt $prefix.Length; $index++) {
            if ($Bytes[$index] -ne $prefix[$index]) { $matches = $false; break }
        }
        if ($matches) { return $true }
    }
    if ($Bytes.Length -ge 12) {
        $riff = [Text.Encoding]::ASCII.GetString($Bytes, 0, 4)
        $webp = [Text.Encoding]::ASCII.GetString($Bytes, 8, 4)
        if ($riff -eq 'RIFF' -and $webp -eq 'WEBP') { return $true }
    }
    return $false
}

function Assert-Status {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][int]$Expected, [Parameter(Mandatory = $true)][string]$Label)
    if ($null -ne $Response.TransportError) { Add-Failure $Label 'request returned no HTTP response'; return $false }
    if ($Response.Status -ne $Expected) { Add-Failure $Label "expected HTTP $Expected but received $($Response.Status)"; return $false }
    return $true
}

function Assert-AllowedMedia {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if ($Response.Status -notin @(200,206)) { Add-Failure $Label "expected HTTP 200/206 but received $($Response.Status)"; return }
    if ($Response.ContentType -notlike 'image/*' -or -not (Test-ImageMagic -Bytes $Response.Body)) {
        Add-Failure $Label 'authorized response did not contain recognized image bytes'
    }
}

function Assert-MaintenanceDenied {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if (-not (Assert-Status -Response $Response -Expected 503 -Label $Label)) { return }
    if ($Response.ContentType -like 'image/*') {
        Add-Failure $Label 'maintenance response retained an image MIME type'
    }
    if (Test-ImageMagic -Bytes $Response.Body) {
        Add-Failure $Label 'maintenance response contained image bytes'
    }
    if ($Response.Body.Length -gt 256 -or $Response.Text -match '(?i)(SQLSTATE|mysqli|stack trace|fatal error|PHP warning|/var/www/)') {
        Add-Failure $Label 'maintenance response exposed an oversized or diagnostic body'
    }
    if ($Response.Text -ne "Class Archive maintenance mode.`n") {
        Add-Failure $Label 'maintenance response was not the exact generic marker response'
    }
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^[0-9]{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) { throw 'Invalid localhost HTTP port.' }

$baseUri = [Uri]("http://127.0.0.1:$port/")
$webServiceUri = [Uri]::new($baseUri, 'ws.php?format=json')
$internalMaintenanceUri = [Uri]::new($baseUri, '_class_archive_internal/maintenance')
$script:composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)
$runId = New-RunId
$ownershipAttempted = $false
$preflightReady = $false
$cleanupVerified = $false
$recoveryVerified = $false
$previewUri = $null
$originalUri = $null
$adminSession = $null
$adminLease = $null

Write-Output "MAINTENANCE_HTTP_RUN=$runId"

try {
    $initial = Invoke-Fixture -Action status -RunId $runId
    if ($initial.marker -ne 'ABSENT' -or $initial.owner -ne 'ABSENT') {
        throw 'Maintenance gate or test owner already exists; refusing to interfere.'
    }
    $media = Invoke-Fixture -Action resolve -RunId $runId
    $imageId = [int]$media.image_id
    $originalPath = [string]$media.original_path
    if ($imageId -le 0 -or $originalPath -notmatch '^upload/[A-Za-z0-9_./-]+$') {
        throw 'Fixture returned an unsafe media reference.'
    }

    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:composeBase -AdminUsername $adminUsername
    $adminSession = $adminLease.Session
    [void](Assert-Status -Response (Invoke-Http -Uri $baseUri -Session $adminSession) -Expected 200 -Label 'preflight/ordinary-page')
    $pictureUri = [Uri]::new($baseUri, "picture.php?/$imageId")
    $picture = Invoke-Http -Uri $pictureUri -Session $adminSession
    if (-not (Assert-Status -Response $picture -Expected 200 -Label 'preflight/picture-viewer')) {
        throw 'Authorized viewer preflight failed.'
    }
    $mainTag = [regex]::Match($picture.Text, '<img(?=[^>]*\bid=["'']theMainImage["''])[^>]*>', 'IgnoreCase')
    $previewMatch = if ($mainTag.Success) { [regex]::Match($mainTag.Value, '\bsrc=["'']([^"'']+)["'']', 'IgnoreCase') } else { $null }
    if ($null -eq $previewMatch -or -not $previewMatch.Success) {
        throw 'Protected preview URL was not rendered by the real viewer.'
    }
    $previewUri = [Uri]::new($baseUri, [Net.WebUtility]::HtmlDecode($previewMatch.Groups[1].Value))
    $originalUri = [Uri]::new($baseUri, $originalPath)
    Assert-AllowedMedia (Invoke-Http -Uri $previewUri -Session $adminSession -MaxBodyBytes 128) 'preflight/preview'
    Assert-AllowedMedia (Invoke-Http -Uri $originalUri -Session $adminSession -MaxBodyBytes 128) 'preflight/original'
    if ($script:failures.Count -gt 0) { throw 'Healthy preflight did not pass.' }
    $preflightReady = $true

    $ownershipAttempted = $true
    $opened = Invoke-Fixture -Action open -RunId $runId
    if ($opened.state -ne 'OPEN' -or -not [bool]$opened.owned) {
        throw 'Exact maintenance marker was not opened by this run.'
    }

    Assert-MaintenanceDenied (Invoke-Http -Uri $baseUri -Session $adminSession -MaxBodyBytes 512) 'maintenance/ordinary-page'
    Assert-MaintenanceDenied (Invoke-Http -Uri $previewUri -Session $adminSession -MaxBodyBytes 512) 'maintenance/preview'
    Assert-MaintenanceDenied (Invoke-Http -Uri $originalUri -Session $adminSession -MaxBodyBytes 512) 'maintenance/original'
    [void](Assert-Status -Response (Invoke-Http -Uri $internalMaintenanceUri -Session $adminSession -MaxBodyBytes 512) -Expected 404 -Label 'maintenance/internal-uri-external-denied')
}
catch {
    Add-Failure 'suite/runtime' $_.Exception.Message
}
finally {
    try {
        if ($ownershipAttempted) {
            try {
                $closed = Invoke-Fixture -Action close -RunId $runId
                if ($closed.state -ne 'ABSENT' -or -not [bool]$closed.owned -or -not [bool]$closed.closed) {
                    throw 'Run-owned maintenance marker did not close exactly.'
                }
                $cleanupVerified = $true
            }
            catch { Add-Failure 'cleanup/marker' $_.Exception.Message }
        }

        try {
            $final = Invoke-Fixture -Action status -RunId $runId
            if ($final.marker -ne 'ABSENT' -or $final.owner -ne 'ABSENT') {
                throw 'Maintenance marker or owner sidecar remains after cleanup.'
            }
            if ($preflightReady -and $cleanupVerified) {
                [void](Assert-Status -Response (Invoke-Http -Uri $baseUri -Session $adminSession) -Expected 200 -Label 'recovery/ordinary-page')
                Assert-AllowedMedia (Invoke-Http -Uri $previewUri -Session $adminSession -MaxBodyBytes 128) 'recovery/preview'
                Assert-AllowedMedia (Invoke-Http -Uri $originalUri -Session $adminSession -MaxBodyBytes 128) 'recovery/original'
                $recoveryVerified = $script:failures.Count -eq 0
            }
        }
        catch { Add-Failure 'recovery/runtime' $_.Exception.Message }
    }
    finally {
        # Session revocation is the outer cleanup boundary: marker recovery or
        # status/HTTP verification failure must never skip exact lease cleanup.
        if ($null -ne $adminLease) {
            try { Remove-ClassArchiveSystemAdminSession -Lease $adminLease }
            catch { Add-Failure 'cleanup/admin-session' 'exact SYSTEM_ADMIN test-session revocation failed' }
        }
    }
}

if ($script:failures.Count -gt 0) {
    Write-Output "MAINTENANCE_HTTP=FAIL probes=$script:probeCount failures=$($script:failures.Count) cleanup=$cleanupVerified recovery=$recoveryVerified"
    foreach ($failure in $script:failures) { Write-Output " - $failure" }
    exit 1
}

Write-Output "MAINTENANCE_HTTP=PASS probes=$script:probeCount marker=removed recovery=verified"
exit 0
