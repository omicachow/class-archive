[CmdletBinding()]
param()

# This is the production-facing regression contract for ClassMediaGuard. Every
# authorization assertion below is a real HTTP request. Backend access is used
# only to provision synthetic role accounts and resolve the storage path of the
# already-seeded synthetic photos; it is never used as authorization evidence.
#
# This is a production gate: a secure MediaGuard runtime must pass. The script
# was first run against the prior static-media configuration and reproduced the
# known URL bypass before the gateway implementation was enabled.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$script:failures = [Collections.Generic.List[string]]::new()
$script:probeCount = 0

function Stop-Setup {
    param([Parameter(Mandatory = $true)][string]$Message)

    [Console]::Error.WriteLine("MEDIA_GUARD_HTTP=ERROR $Message")
    exit 2
}

function Add-Failure {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$Message
    )

    $entry = "$Label :: $Message"
    if (-not $script:failures.Contains($entry)) {
        $script:failures.Add($entry)
    }
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)

    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { Stop-Setup 'Invalid .env.piwigo syntax.' }
        $key = $trimmed.Substring(0, $separator)
        $value = $trimmed.Substring($separator + 1)
        $values[$key] = $value
    }
    return $values
}

function Require-Setting {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$Key
    )

    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Settings[$Key])) {
        Stop-Setup "Missing required local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function New-TransientPassword {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-WebService {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Body
    )

    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function New-AuthenticatedSession {
    param(
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$RoleLabel
    )

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    try {
        $response = Invoke-WebService -Uri $WebServiceUri -Session $session -Body @{
            method = 'pwg.session.login'
            username = $Username
            password = $Password
        }
    }
    catch {
        Stop-Setup "Synthetic login failed for role $RoleLabel."
    }
    if ($response.stat -ne 'ok' -or -not $response.result) {
        Stop-Setup "Synthetic login was rejected for role $RoleLabel."
    }
    return $session
}

function Invoke-Logout {
    param(
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][string]$Label
    )

    try {
        $response = Invoke-WebService -Uri $WebServiceUri -Session $Session -Body @{
            method = 'pwg.session.logout'
        }
    }
    catch {
        Stop-Setup "Logout request failed for $Label."
    }
    if ($response.stat -ne 'ok') {
        Stop-Setup "Logout was rejected for $Label."
    }
}

function Invoke-HttpProbe {
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
    $request.Timeout = 20000
    $request.ReadWriteTimeout = 20000
    $request.UserAgent = 'ClassArchive-MediaGuard-Regression/1.0'
    $request.AutomaticDecompression = [Net.DecompressionMethods]::GZip -bor [Net.DecompressionMethods]::Deflate
    if ($Mode -eq 'RANGE') {
        $request.AddRange(0, 31)
    }

    $response = $null
    $transportError = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -ne $_.Exception.Response) {
            $response = [Net.HttpWebResponse]$_.Exception.Response
        }
        else {
            $transportError = 'request failed without an HTTP response'
        }
    }

    if ($null -eq $response) {
        return [pscustomobject]@{
            Status = 0
            ContentType = ''
            ContentLength = -1L
            Headers = @{}
            Body = [byte[]]@()
            TransportError = $transportError
        }
    }

    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) {
            $headers[$key] = [string]$response.Headers[$key]
        }
        # Read only the prefix needed to prove that an allowed response really
        # contains image bytes, and that a denial did not smuggle image bytes
        # behind a misleading status or Content-Type. RANGE reads one byte past
        # the requested length so an oversized body cannot pass as 32 bytes.
        $bodyLimit = if ($Mode -eq 'HEAD') { 1 } elseif ($Mode -eq 'RANGE') { 33 } else { 64 }
        $body = [IO.MemoryStream]::new()
        $stream = $response.GetResponseStream()
        try {
            if ($null -ne $stream) {
                $buffer = New-Object byte[] $bodyLimit
                while ($body.Length -lt $bodyLimit) {
                    $remaining = [int]($bodyLimit - $body.Length)
                    $read = $stream.Read($buffer, 0, $remaining)
                    if ($read -le 0) { break }
                    $body.Write($buffer, 0, $read)
                }
            }
        }
        finally {
            if ($null -ne $stream) { $stream.Dispose() }
        }
        $bodyBytes = $body.ToArray()
        $body.Dispose()
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            ContentLength = [long]$response.ContentLength
            Headers = $headers
            Body = $bodyBytes
            TransportError = $null
        }
    }
    finally {
        $response.Dispose()
    }
}

function Invoke-RawTargetProbe {
    param(
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][string]$RequestTarget,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'HEAD', 'RANGE')][string]$Mode = 'GET'
    )

    # Uri/HttpWebRequest canonicalizes dot segments before sending them. These
    # probes intentionally write the HTTP/1.1 request target to a loopback TCP
    # socket so encoded and non-normalized paths reach nginx byte-for-byte.
    $hostAddresses = @([Net.Dns]::GetHostAddresses($BaseUri.Host))
    $isLoopback = $hostAddresses.Count -gt 0 -and [Net.IPAddress]::IsLoopback($hostAddresses[0])
    $invalidBase = $BaseUri.Scheme -ne 'http' -or -not $isLoopback
    $invalidTarget = -not $RequestTarget.StartsWith('/') -or $RequestTarget -match "[`r`n]" -or $RequestTarget.Length -gt 8192
    if ($invalidBase -or $invalidTarget) {
        Stop-Setup 'Unsafe raw HTTP normalization probe configuration.'
    }

    $script:probeCount++
    $client = [Net.Sockets.TcpClient]::new()
    $stream = $null
    try {
        $client.ReceiveTimeout = 20000
        $client.SendTimeout = 20000
        $client.Connect($BaseUri.Host, $BaseUri.Port)
        $stream = $client.GetStream()

        $method = if ($Mode -eq 'HEAD') { 'HEAD' } else { 'GET' }
        $hostHeader = if ($BaseUri.IsDefaultPort) { $BaseUri.Host } else { "$($BaseUri.Host):$($BaseUri.Port)" }
        $requestLines = [Collections.Generic.List[string]]::new()
        $requestLines.Add("$method $RequestTarget HTTP/1.1")
        $requestLines.Add("Host: $hostHeader")
        $requestLines.Add('User-Agent: ClassArchive-MediaGuard-Regression/1.0')
        $requestLines.Add('Accept: */*')
        $requestLines.Add('Accept-Encoding: identity')
        $requestLines.Add('Connection: close')
        if ($Mode -eq 'RANGE') {
            $requestLines.Add('Range: bytes=0-31')
        }

        $cookies = @($Session.Cookies.GetCookies($BaseUri))
        if ($cookies.Count -gt 0) {
            $cookieValue = ($cookies | ForEach-Object { "$($_.Name)=$($_.Value)" }) -join '; '
            $requestLines.Add("Cookie: $cookieValue")
        }

        $requestText = ($requestLines -join "`r`n") + "`r`n`r`n"
        $requestBytes = [Text.Encoding]::ASCII.GetBytes($requestText)
        $stream.Write($requestBytes, 0, $requestBytes.Length)
        $stream.Flush()

        $received = [IO.MemoryStream]::new()
        $buffer = New-Object byte[] 4096
        $headerText = $null
        $headerEnd = -1
        while ($received.Length -lt 65536) {
            $read = $stream.Read($buffer, 0, $buffer.Length)
            if ($read -le 0) { break }
            $received.Write($buffer, 0, $read)
            $candidate = [Text.Encoding]::ASCII.GetString($received.ToArray())
            $headerEnd = $candidate.IndexOf("`r`n`r`n", [StringComparison]::Ordinal)
            if ($headerEnd -ge 0) {
                $headerText = $candidate.Substring(0, $headerEnd)
                break
            }
        }

        if ($null -eq $headerText) {
            $received.Dispose()
            return [pscustomobject]@{
                Status = 0
                ContentType = ''
                ContentLength = -1L
                Headers = @{}
                Body = [byte[]]@()
                TransportError = 'raw request returned no complete HTTP headers'
            }
        }

        # Retain only a small wire-body prefix. This makes raw normalization
        # denials prove that no image bytes were returned, without buffering a
        # potentially large media response.
        $wireBodyMemory = [IO.MemoryStream]::new()
        $receivedBytes = $received.ToArray()
        $received.Dispose()
        $bodyStart = $headerEnd + 4
        if ($receivedBytes.Length -gt $bodyStart) {
            $alreadyRead = [Math]::Min(256, $receivedBytes.Length - $bodyStart)
            $wireBodyMemory.Write($receivedBytes, $bodyStart, $alreadyRead)
        }
        while ($Mode -ne 'HEAD' -and $wireBodyMemory.Length -lt 256) {
            $remaining = [int](256 - $wireBodyMemory.Length)
            $read = $stream.Read($buffer, 0, [Math]::Min($buffer.Length, $remaining))
            if ($read -le 0) { break }
            $wireBodyMemory.Write($buffer, 0, $read)
        }
        $wireBody = $wireBodyMemory.ToArray()
        $wireBodyMemory.Dispose()

        $lines = $headerText -split "`r`n"
        $statusMatch = [regex]::Match($lines[0], '^HTTP/[0-9.]+\s+([0-9]{3})(?:\s|$)')
        if (-not $statusMatch.Success) {
            return [pscustomobject]@{
                Status = 0
                ContentType = ''
                ContentLength = -1L
                Headers = @{}
                Body = [byte[]]@()
                TransportError = 'raw request returned an invalid HTTP status line'
            }
        }

        $headers = @{}
        foreach ($line in $lines | Select-Object -Skip 1) {
            $separator = $line.IndexOf(':')
            if ($separator -lt 1) { continue }
            $name = $line.Substring(0, $separator).Trim()
            $value = $line.Substring($separator + 1).Trim()
            if ($headers.ContainsKey($name)) {
                $headers[$name] = [string]$headers[$name] + ', ' + $value
            }
            else {
                $headers[$name] = $value
            }
        }
        $contentType = ''
        $contentLength = -1L
        foreach ($name in $headers.Keys) {
            if ([string]::Equals([string]$name, 'Content-Type', [StringComparison]::OrdinalIgnoreCase)) {
                $contentType = [string]$headers[$name]
            }
            if ([string]::Equals([string]$name, 'Content-Length', [StringComparison]::OrdinalIgnoreCase)) {
                [void][long]::TryParse([string]$headers[$name], [ref]$contentLength)
            }
        }

        $body = $wireBody
        $transferEncoding = ''
        foreach ($name in $headers.Keys) {
            if ([string]::Equals([string]$name, 'Transfer-Encoding', [StringComparison]::OrdinalIgnoreCase)) {
                $transferEncoding = [string]$headers[$name]
            }
        }
        if ($transferEncoding -match '(?i)(?:^|,)\s*chunked\s*(?:,|$)' -and $wireBody.Length -gt 0) {
            $chunkLineEnd = -1
            for ($index = 0; $index -lt ($wireBody.Length - 1); $index++) {
                if ($wireBody[$index] -eq 13 -and $wireBody[$index + 1] -eq 10) {
                    $chunkLineEnd = $index
                    break
                }
            }
            if ($chunkLineEnd -ge 1) {
                $chunkLine = [Text.Encoding]::ASCII.GetString($wireBody, 0, $chunkLineEnd)
                $chunkMatch = [regex]::Match($chunkLine, '^([0-9A-Fa-f]+)(?:;.*)?$')
                if ($chunkMatch.Success) {
                    $chunkLength = [Convert]::ToInt32($chunkMatch.Groups[1].Value, 16)
                    $chunkStart = $chunkLineEnd + 2
                    $available = [Math]::Max(0, $wireBody.Length - $chunkStart)
                    $chunkBytes = [Math]::Min($chunkLength, $available)
                    if ($chunkBytes -gt 0) {
                        $body = [byte[]]$wireBody[$chunkStart..($chunkStart + $chunkBytes - 1)]
                    }
                    else {
                        $body = [byte[]]@()
                    }
                }
            }
        }
        if ($body.Length -gt 64) {
            $body = [byte[]]$body[0..63]
        }

        return [pscustomobject]@{
            Status = [int]$statusMatch.Groups[1].Value
            ContentType = $contentType
            ContentLength = $contentLength
            Headers = $headers
            Body = [byte[]]$body
            TransportError = $null
        }
    }
    catch {
        return [pscustomobject]@{
            Status = 0
            ContentType = ''
            ContentLength = -1L
            Headers = @{}
            Body = [byte[]]@()
            TransportError = 'raw request failed without a complete HTTP response'
        }
    }
    finally {
        if ($null -ne $stream) { $stream.Dispose() }
        $client.Dispose()
    }
}

function Get-HeaderValue {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Name
    )

    foreach ($key in $Response.Headers.Keys) {
        if ([string]::Equals([string]$key, $Name, [StringComparison]::OrdinalIgnoreCase)) {
            return [string]$Response.Headers[$key]
        }
    }
    return ''
}

function Assert-PrivateCachePolicy {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    $cacheControl = Get-HeaderValue -Response $Response -Name 'Cache-Control'
    if ([string]::IsNullOrWhiteSpace($cacheControl)) {
        Add-Failure $Label 'missing explicit private/no-cache Cache-Control'
        return
    }
    if ($cacheControl -match '(?i)(?:^|,)\s*public\b' -or $cacheControl -match '(?i)\bs-maxage\s*=') {
        Add-Failure $Label 'response declares shared/public caching'
    }
    if ($cacheControl -notmatch '(?i)(?:^|,)\s*(?:private|no-store)\b') {
        Add-Failure $Label 'Cache-Control is not explicitly private or no-store'
    }
}

function Test-BytePrefix {
    param(
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][byte[]]$Bytes,
        [Parameter(Mandatory = $true)][byte[]]$Prefix
    )

    if ($Bytes.Length -lt $Prefix.Length) { return $false }
    for ($index = 0; $index -lt $Prefix.Length; $index++) {
        if ($Bytes[$index] -ne $Prefix[$index]) { return $false }
    }
    return $true
}

function Test-ImageMagic {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][byte[]]$Bytes)

    if (Test-BytePrefix $Bytes ([byte[]](0xFF, 0xD8, 0xFF))) { return $true } # JPEG
    if (Test-BytePrefix $Bytes ([byte[]](0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A))) { return $true } # PNG
    if (Test-BytePrefix $Bytes ([Text.Encoding]::ASCII.GetBytes('GIF87a'))) { return $true }
    if (Test-BytePrefix $Bytes ([Text.Encoding]::ASCII.GetBytes('GIF89a'))) { return $true }
    if (Test-BytePrefix $Bytes ([byte[]](0x42, 0x4D))) { return $true } # BMP
    if (Test-BytePrefix $Bytes ([byte[]](0x49, 0x49, 0x2A, 0x00))) { return $true } # TIFF little-endian
    if (Test-BytePrefix $Bytes ([byte[]](0x4D, 0x4D, 0x00, 0x2A))) { return $true } # TIFF big-endian
    if (
        ($Bytes.Length -ge 12) -and
        ([Text.Encoding]::ASCII.GetString($Bytes, 0, 4) -eq 'RIFF') -and
        ([Text.Encoding]::ASCII.GetString($Bytes, 8, 4) -eq 'WEBP')
    ) { return $true }
    if ($Bytes.Length -ge 12 -and [Text.Encoding]::ASCII.GetString($Bytes, 4, 4) -eq 'ftyp') {
        $brand = [Text.Encoding]::ASCII.GetString($Bytes, 8, 4)
        if ($brand -in @('avif', 'avis', 'heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1')) { return $true }
    }
    return $false
}

function Assert-AllowedMedia {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$Mode
    )

    if ($null -ne $Response.TransportError) {
        Add-Failure $Label $Response.TransportError
        return
    }
    $expectedStatus = if ($Mode -eq 'RANGE') { 206 } else { 200 }
    if ($Response.Status -ne $expectedStatus) {
        Add-Failure $Label "authorized request returned HTTP $($Response.Status)"
        return
    }
    if ($Response.ContentType -notlike 'image/*') {
        Add-Failure $Label 'authorized response is not an image'
    }
    if ($Mode -eq 'HEAD') {
        if ($Response.Body.Length -ne 0) {
            Add-Failure $Label "HEAD response contained $($Response.Body.Length) body byte(s)"
        }
    }
    elseif (-not (Test-ImageMagic -Bytes $Response.Body)) {
        Add-Failure $Label 'authorized response body has no recognized image magic'
    }
    if ($Mode -eq 'RANGE') {
        $contentRange = Get-HeaderValue -Response $Response -Name 'Content-Range'
        if ($contentRange -notmatch '^bytes 0-31/([1-9][0-9]*)$' -or [long]$Matches[1] -lt 32) {
            Add-Failure $Label 'partial response has an invalid Content-Range'
        }
        if ($Response.ContentLength -ne 32) {
            Add-Failure $Label "partial response Content-Length is $($Response.ContentLength), expected 32"
        }
        if ($Response.Body.Length -ne 32) {
            Add-Failure $Label "partial response body contains $($Response.Body.Length) bytes, expected 32"
        }
    }
    Assert-PrivateCachePolicy -Response $Response -Label $Label
}

function Assert-DeniedMedia {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($null -ne $Response.TransportError) {
        Add-Failure $Label $Response.TransportError
        return
    }
    if ($Response.Status -ne 403) {
        Add-Failure $Label "authorization denial must be HTTP 403, got $($Response.Status)"
    }
    if ($Response.ContentType -like 'image/*') {
        Add-Failure $Label 'denied response still returned image media'
    }
    if (Test-ImageMagic -Bytes $Response.Body) {
        Add-Failure $Label 'denied response body still contains recognized image bytes'
    }
    Assert-PrivateCachePolicy -Response $Response -Label $Label
}

function Assert-FailClosed {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if ($null -ne $Response.TransportError) {
        Add-Failure $Label $Response.TransportError
        return
    }
    if ($Response.Status -notin @(400, 401, 403, 404, 410)) {
        Add-Failure $Label "tampered/guessed request did not fail closed (HTTP $($Response.Status))"
    }
    if ($Response.ContentType -like 'image/*') {
        Add-Failure $Label 'tampered/guessed request returned image media'
    }
    if (Test-ImageMagic -Bytes $Response.Body) {
        Add-Failure $Label 'tampered/guessed response body contains recognized image bytes'
    }
    Assert-PrivateCachePolicy -Response $Response -Label $Label
}

function Convert-ToAbsoluteUri {
    param(
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][string]$Reference
    )

    $decoded = [Net.WebUtility]::HtmlDecode($Reference)
    return [Uri]::new($BaseUri, $decoded)
}

function Get-CanonicalStoragePath {
    param(
        [Parameter(Mandatory = $true)][int]$ImageId,
        [Parameter(Mandatory = $true)][array]$ComposeBase
    )

    $php = @'
<?php
chdir('/var/www/html/piwigo');
define('PHPWG_ROOT_PATH', './');
$_SERVER['SCRIPT_NAME'] = '/ws.php';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ob_start();
require PHPWG_ROOT_PATH.'include/common.inc.php';
ob_end_clean();
$id = (int) getenv('CLASS_ARCHIVE_MEDIA_IMAGE_ID');
$result = pwg_query('SELECT path FROM '.IMAGES_TABLE.' WHERE id = '.$id);
$row = pwg_db_fetch_assoc($result);
if (!$row) { fwrite(STDERR, "fixture image missing\n"); exit(3); }
echo preg_replace('#^\./#', '', (string) $row['path']);
'@
    # Send source on stdin: wsl.exe reconstructs a Linux command line and would
    # otherwise let the shell expand dollar-prefixed PHP variables in `php -r`.
    $output = $php | & wsl.exe @($ComposeBase + @(
        'exec', '-T', '--user', 'nginx',
        '-e', "CLASS_ARCHIVE_MEDIA_IMAGE_ID=$ImageId",
        'piwigo', 'php', '-d', 'output_buffering=4096'
    ))
    if ($LASTEXITCODE -ne 0) {
        Stop-Setup 'Could not resolve a synthetic image storage path.'
    }
    $path = ($output -join '').Trim()
    if ($path -notmatch '^upload/[A-Za-z0-9_./-]+$' -or $path -match '(?:^|/)\.\.(?:/|$)') {
        Stop-Setup 'Synthetic image returned an unsafe or unexpected storage path.'
    }
    return $path
}

function Resolve-FixtureMedia {
    param(
        [Parameter(Mandatory = $true)][string]$Era,
        [Parameter(Mandatory = $true)][string]$AlbumPermalink,
        [Parameter(Mandatory = $true)][Uri]$BaseUri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$AdminSession,
        [Parameter(Mandatory = $true)][array]$ComposeBase
    )

    try {
        $album = Invoke-WebRequest -UseBasicParsing `
            -Uri ([Uri]::new($BaseUri, "index.php?/category/$AlbumPermalink")) `
            -WebSession $AdminSession -TimeoutSec 30
    }
    catch {
        Stop-Setup "Could not open the synthetic $Era album."
    }
    $pictureLink = $album.Links | Where-Object { $_.href -match '^picture\.php\?/[0-9]+' } | Select-Object -First 1
    if ($null -eq $pictureLink) {
        Stop-Setup "Synthetic $Era album has no photo."
    }
    $idMatch = [regex]::Match([string]$pictureLink.href, '^picture\.php\?/([0-9]+)')
    if (-not $idMatch.Success) {
        Stop-Setup "Synthetic $Era picture link has no image id."
    }
    $imageId = [int]$idMatch.Groups[1].Value

    try {
        $picture = Invoke-WebRequest -UseBasicParsing `
            -Uri ([Uri]::new($BaseUri, [string]$pictureLink.href)) `
            -WebSession $AdminSession -TimeoutSec 30
    }
    catch {
        Stop-Setup "Could not open the synthetic $Era picture page."
    }

    $mainTag = [regex]::Match($picture.Content, '<img(?=[^>]*\bid="theMainImage")[^>]*>', 'IgnoreCase')
    $previewMatch = if ($mainTag.Success) {
        [regex]::Match($mainTag.Value, '\bsrc="([^"]+)"', 'IgnoreCase')
    }
    else { $null }
    $activeThumbnailBlock = [regex]::Match(
        $picture.Content,
        '<a(?=[^>]*\bid="thumbnail-active")[^>]*>[\s\S]{0,2000}?</a>',
        'IgnoreCase'
    )
    $thumbnailImageTag = if ($activeThumbnailBlock.Success) {
        [regex]::Match($activeThumbnailBlock.Value, '<img[^>]*>', 'IgnoreCase')
    }
    else { $null }
    $thumbnailMatch = $null
    if ($null -ne $thumbnailImageTag -and $thumbnailImageTag.Success) {
        # Prefer the final lazy-load target. Already-generated derivatives use
        # data-lazy directly; missing ones use data-src after a placeholder.
        $thumbnailMatch = [regex]::Match($thumbnailImageTag.Value, '\bdata-src="([^"]+)"', 'IgnoreCase')
        if (-not $thumbnailMatch.Success) {
            $thumbnailMatch = [regex]::Match($thumbnailImageTag.Value, '\bdata-lazy="([^"]+)"', 'IgnoreCase')
        }
        if (-not $thumbnailMatch.Success) {
            $thumbnailMatch = [regex]::Match($thumbnailImageTag.Value, '\bsrc="([^"]+)"', 'IgnoreCase')
        }
    }
    $originalTag = [regex]::Match($picture.Content, '<a(?=[^>]*\bid="downloadSwitchLink")[^>]*>', 'IgnoreCase')
    $originalMatch = if ($originalTag.Success) {
        [regex]::Match($originalTag.Value, '\bhref="([^"]+)"', 'IgnoreCase')
    }
    else { $null }
    if ($null -eq $previewMatch -or -not $previewMatch.Success) {
        Stop-Setup "Synthetic $Era viewer has no main preview URL."
    }
    if ($null -eq $thumbnailMatch -or -not $thumbnailMatch.Success) {
        Stop-Setup "Synthetic $Era viewer has no thumbnail URL."
    }
    if ($null -eq $originalMatch -or -not $originalMatch.Success) {
        Stop-Setup "Synthetic $Era viewer has no protected original URL."
    }

    $storagePath = Get-CanonicalStoragePath -ImageId $imageId -ComposeBase $ComposeBase
    $extension = [IO.Path]::GetExtension($storagePath)
    $stem = $storagePath.Substring(0, $storagePath.Length - $extension.Length)

    return [pscustomobject]@{
        Era = $Era
        ImageId = $imageId
        Guarded = [ordered]@{
            Thumb = Convert-ToAbsoluteUri -BaseUri $BaseUri -Reference $thumbnailMatch.Groups[1].Value
            Preview = Convert-ToAbsoluteUri -BaseUri $BaseUri -Reference $previewMatch.Groups[1].Value
            Original = Convert-ToAbsoluteUri -BaseUri $BaseUri -Reference $originalMatch.Groups[1].Value
        }
        Raw = [ordered]@{
            Thumb = [Uri]::new($BaseUri, "_data/i/$stem-sq$extension")
            Preview = [Uri]::new($BaseUri, "_data/i/$stem-me$extension")
            Original = [Uri]::new($BaseUri, $storagePath)
        }
        StoragePath = $storagePath
    }
}

function Add-QueryValue {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][string]$Value
    )

    $separator = if ($Uri.OriginalString.Contains('?')) { '&' } else { '?' }
    return [Uri]($Uri.OriginalString + $separator + $Value)
}

function Get-SignedUrlTamper {
    param([Parameter(Mandatory = $true)][Uri]$Uri)

    $text = $Uri.OriginalString
    $match = [regex]::Match($text, '(?i)([?&](?:sig|signature|token|expires)=)([^&]+)')
    if (-not $match.Success) { return $null }
    $replacement = $match.Groups[1].Value + 'invalid'
    return [Uri]($text.Substring(0, $match.Index) + $replacement + $text.Substring($match.Index + $match.Length))
}

if (-not (Test-Path -LiteralPath $envPath)) {
    Stop-Setup 'Missing ignored .env.piwigo.'
}
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
$adminPassword = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_PASSWORD'
if ($port -notmatch '^[0-9]{1,5}$') { Stop-Setup 'Invalid local HTTP port.' }
$baseUri = [Uri]("http://127.0.0.1:$port/")
$webServiceUri = [Uri]::new($baseUri, 'ws.php?format=json')
$composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

# Fixture provisioning only rotates passwords for the four synthetic test
# accounts and restores their single expected group. It does not touch albums,
# photos, comments, or non-fixture users.
$fixturePassword = New-TransientPassword
$provisionOutput = & wsl.exe @($composeBase + @(
    'exec', '-T', '--user', 'nginx',
    '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
    'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
))
if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin @($provisionOutput)) {
    Stop-Setup 'Synthetic access fixture provisioning failed.'
}

$sessions = [ordered]@{
    Admin = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $adminUsername -Password $adminPassword -RoleLabel 'Admin'
    Classmate = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username 'fixture-classmate' -Password $fixturePassword -RoleLabel 'Classmate'
    Teacher = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username 'fixture-teacher' -Password $fixturePassword -RoleLabel 'Teacher'
    Family = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username 'fixture-family' -Password $fixturePassword -RoleLabel 'Family'
    Anonymous = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username 'fixture-anonymous' -Password $fixturePassword -RoleLabel 'Anonymous'
    Guest = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
}

$media = [ordered]@{
    Heritage = Resolve-FixtureMedia `
        -Era 'Heritage' -AlbumPermalink 'fixture-heritage-graduation' `
        -BaseUri $baseUri -AdminSession $sessions.Admin -ComposeBase $composeBase
    Living = Resolve-FixtureMedia `
        -Era 'Living' -AlbumPermalink 'fixture-living-reunion' `
        -BaseUri $baseUri -AdminSession $sessions.Admin -ComposeBase $composeBase
}

$policy = @(
    # Put deny-first roles first so a capped failure report surfaces ACL leaks
    # before lower-severity cache-policy findings on authorized responses.
    # Original mirrors the locked Phase 0 defaults: FAMILY and ANONYMOUS can
    # view previews but cannot download original files.
    [pscustomobject]@{ Role = 'Guest'; Heritage = $false; Living = $false; Original = $false },
    [pscustomobject]@{ Role = 'Family'; Heritage = $true; Living = $false; Original = $false },
    [pscustomobject]@{ Role = 'Anonymous'; Heritage = $true; Living = $true; Original = $false },
    [pscustomobject]@{ Role = 'Classmate'; Heritage = $true; Living = $true; Original = $true },
    [pscustomobject]@{ Role = 'Teacher'; Heritage = $true; Living = $true; Original = $true },
    [pscustomobject]@{ Role = 'Admin'; Heritage = $true; Living = $true; Original = $true }
)

# Matrix: URLs discovered by Admin are replayed unchanged by every role. This
# catches both ordinary access failures and bearer-like URL leakage.
foreach ($rule in $policy) {
    foreach ($eraName in @('Heritage', 'Living')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            $expectedAllow = [bool]$rule.$eraName -and (
                $variant -ne 'Original' -or [bool]$rule.Original
            )
            foreach ($mode in @('GET', 'HEAD', 'RANGE')) {
                $label = "matrix/$($rule.Role)/$eraName/$variant/$mode"
                $response = Invoke-HttpProbe `
                    -Uri $media[$eraName].Guarded[$variant] `
                    -Session $sessions[$rule.Role] -Mode $mode
                if ($expectedAllow) {
                    Assert-AllowedMedia -Response $response -Label $label -Mode $mode
                }
                else {
                    Assert-DeniedMedia -Response $response -Label $label
                }
            }
        }
    }
}

# Known storage paths must be governed by the same role/Era/original policy as
# viewer URLs. They are deliberately replayed directly to prove that nginx does
# not serve static bytes before MediaGuard runs.
foreach ($rule in $policy) {
    foreach ($eraName in @('Heritage', 'Living')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            $expectedAllow = [bool]$rule.$eraName -and (
                $variant -ne 'Original' -or [bool]$rule.Original
            )
            foreach ($mode in @('GET', 'HEAD', 'RANGE')) {
                $label = "known-path/$($rule.Role)/$eraName/$variant/$mode"
                $response = Invoke-HttpProbe `
                    -Uri $media[$eraName].Raw[$variant] `
                    -Session $sessions[$rule.Role] -Mode $mode
                if ($expectedAllow) {
                    Assert-AllowedMedia -Response $response -Label $label -Mode $mode
                }
                else {
                    Assert-DeniedMedia -Response $response -Label $label
                }
            }
        }
    }
}

# Logout must revoke known URLs in the same cookie jar.
$logoutSession = New-AuthenticatedSession `
    -WebServiceUri $webServiceUri -Username 'fixture-classmate' `
    -Password $fixturePassword -RoleLabel 'Classmate logout probe'
Invoke-Logout -WebServiceUri $webServiceUri -Session $logoutSession -Label 'Classmate logout probe'
foreach ($eraName in @('Heritage', 'Living')) {
    foreach ($surface in @('Guarded', 'Raw')) {
        foreach ($variant in @('Thumb', 'Preview', 'Original')) {
            foreach ($mode in @('GET', 'HEAD', 'RANGE')) {
                $label = "logout/$surface/$eraName/$variant/$mode"
                $response = Invoke-HttpProbe `
                    -Uri $media[$eraName].$surface[$variant] `
                    -Session $logoutSession -Mode $mode
                Assert-DeniedMedia -Response $response -Label $label
            }
        }
    }
}

# Switching from Admin to Family in one cookie jar must immediately apply the
# Family LIVING denial to already-known URLs.
$switchSession = New-AuthenticatedSession `
    -WebServiceUri $webServiceUri -Username $adminUsername `
    -Password $adminPassword -RoleLabel 'Admin switch probe'
Invoke-Logout -WebServiceUri $webServiceUri -Session $switchSession -Label 'Admin switch probe'
try {
    $switchLogin = Invoke-WebService -Uri $webServiceUri -Session $switchSession -Body @{
        method = 'pwg.session.login'
        username = 'fixture-family'
        password = $fixturePassword
    }
}
catch {
    Stop-Setup 'Family account switch login failed.'
}
if ($switchLogin.stat -ne 'ok' -or -not $switchLogin.result) {
    Stop-Setup 'Family account switch login was rejected.'
}
foreach ($surface in @('Guarded', 'Raw')) {
    foreach ($variant in @('Thumb', 'Preview', 'Original')) {
        foreach ($mode in @('GET', 'HEAD', 'RANGE')) {
            $label = "switch/Admin-to-Family/$surface/Living/$variant/$mode"
            $response = Invoke-HttpProbe `
                -Uri $media.Living.$surface[$variant] `
                -Session $switchSession -Mode $mode
            Assert-DeniedMedia -Response $response -Label $label
        }
    }
}

# Query/resource tampering. Duplicate IDs exercise parameter pollution, while
# an invalid part and an added fake signature exercise query mutation without
# printing any real signed value.
$duplicateHeritageFirst = [Uri]::new(
    $baseUri,
    "action.php?id=$($media.Heritage.ImageId)&id=$($media.Living.ImageId)&part=e&download"
)
$duplicateLivingFirst = [Uri]::new(
    $baseUri,
    "action.php?id=$($media.Living.ImageId)&id=$($media.Heritage.ImageId)&part=e&download"
)
$invalidPart = [Uri]::new(
    $baseUri,
    "action.php?id=$($media.Heritage.ImageId)&part=..%2F..%2Fupload&download"
)
$fakeSignature = Add-QueryValue -Uri $media.Living.Guarded.Preview -Value 'signature=invalid&expires=1'
foreach ($attack in @(
    [pscustomobject]@{ Label = 'query/duplicate-id/heritage-first'; Uri = $duplicateHeritageFirst; Session = $sessions.Classmate },
    [pscustomobject]@{ Label = 'query/duplicate-id/living-first'; Uri = $duplicateLivingFirst; Session = $sessions.Classmate },
    [pscustomobject]@{ Label = 'query/invalid-part'; Uri = $invalidPart; Session = $sessions.Classmate },
    [pscustomobject]@{ Label = 'query/fake-signature'; Uri = $fakeSignature; Session = $sessions.Family }
)) {
    $response = Invoke-HttpProbe -Uri $attack.Uri -Session $attack.Session -Mode 'GET'
    Assert-FailClosed -Response $response -Label $attack.Label
}

# If a future implementation emits a signed URL, mutate its security field as
# an otherwise-authorized Classmate and require fail-closed behavior.
foreach ($eraName in @('Heritage', 'Living')) {
    foreach ($variant in @('Thumb', 'Preview', 'Original')) {
        $tampered = Get-SignedUrlTamper -Uri $media[$eraName].Guarded[$variant]
        if ($null -ne $tampered) {
            $label = "query/signed-field/$eraName/$variant"
            $response = Invoke-HttpProbe -Uri $tampered -Session $sessions.Classmate -Mode 'GET'
            Assert-FailClosed -Response $response -Label $label
        }
    }
}

# Encoding and normalization variants must not turn a denied raw path into an
# image response. HttpWebRequest may normalize some of these before transport;
# that is intentional—the normalized request must still reach a deny boundary.
$livingPath = $media.Living.StoragePath
$livingTail = $livingPath.Substring('upload/'.Length)
$normalizationTargets = [ordered]@{
    DotSegment = "/upload/./$livingTail"
    ParentSegment = "/upload/__mediaguard_probe__/../$livingTail"
    EncodedSegment = "/%75pload/$livingTail"
    EncodedSlash = '/upload%2F' + $livingTail
    EncodedDot = "/upload/%2e/$livingTail"
    DoubleSlash = '/upload//' + $livingTail
}
foreach ($entry in $normalizationTargets.GetEnumerator()) {
    foreach ($roleName in @('Guest', 'Family')) {
        $label = "path/$($entry.Key)/$roleName"
        $response = Invoke-RawTargetProbe `
            -BaseUri $baseUri -RequestTarget ([string]$entry.Value) `
            -Session $sessions[$roleName] -Mode 'GET'
        Assert-FailClosed -Response $response -Label $label
    }
}

# Guessing an existing numeric image endpoint, a far-out image id, or a nearby
# hashed filename must not reveal a LIVING photo.
$knownIdGuess = [Uri]::new($baseUri, "action.php?id=$($media.Living.ImageId)&part=e&download")
$missingIdGuess = [Uri]::new($baseUri, "action.php?id=$($media.Living.ImageId + 1000000)&part=e&download")
$pathParts = $livingPath -split '/'
$originalName = $pathParts[-1]
$nameMatch = [regex]::Match($originalName, '^(.*-)([0-9a-f])([^/]*)$')
if (-not $nameMatch.Success) {
    Stop-Setup 'Synthetic storage filename cannot support a safe guess probe.'
}
$replacementNibble = if ($nameMatch.Groups[2].Value -eq '0') { '1' } else { '0' }
$pathParts[-1] = $nameMatch.Groups[1].Value + $replacementNibble + $nameMatch.Groups[3].Value
$nearbyPathGuess = [Uri]::new($baseUri, ($pathParts -join '/'))
foreach ($attack in @(
    [pscustomobject]@{ Label = 'guess/known-image-id/Guest'; Uri = $knownIdGuess; Session = $sessions.Guest; Strict = $true },
    [pscustomobject]@{ Label = 'guess/known-image-id/Family'; Uri = $knownIdGuess; Session = $sessions.Family; Strict = $true },
    [pscustomobject]@{ Label = 'guess/missing-image-id'; Uri = $missingIdGuess; Session = $sessions.Guest; Strict = $false },
    [pscustomobject]@{ Label = 'guess/nearby-storage-path'; Uri = $nearbyPathGuess; Session = $sessions.Guest; Strict = $false }
)) {
    $response = Invoke-HttpProbe -Uri $attack.Uri -Session $attack.Session -Mode 'GET'
    if ($attack.Strict) {
        Assert-DeniedMedia -Response $response -Label $attack.Label
    }
    else {
        Assert-FailClosed -Response $response -Label $attack.Label
    }
}

if ($script:failures.Count -gt 0) {
    Write-Output 'MEDIA_GUARD_HTTP=FAIL'
    Write-Output "HTTP_PROBES=$script:probeCount"
    Write-Output "FAILURES=$($script:failures.Count)"
    $limit = [Math]::Min(80, $script:failures.Count)
    for ($index = 0; $index -lt $limit; $index++) {
        [Console]::Error.WriteLine("FAIL $($script:failures[$index])")
    }
    if ($script:failures.Count -gt $limit) {
        [Console]::Error.WriteLine("FAIL additional failures omitted: $($script:failures.Count - $limit)")
    }
    exit 1
}

Write-Output 'MEDIA_GUARD_HTTP=PASS'
Write-Output "HTTP_PROBES=$script:probeCount"
Write-Output 'ROLE_ERA_VARIANT_MATRIX=PASS'
Write-Output 'KNOWN_URL_LOGOUT_SWITCH=PASS'
Write-Output 'HEAD_RANGE_TAMPER_NORMALIZATION_GUESS=PASS'
Write-Output 'PRIVATE_CACHE_POLICY=PASS'
Write-Output 'ALLOW_BODY_IMAGE_MAGIC=PASS'
Write-Output 'DENY_BODY_IMAGE_MAGIC_ABSENT=PASS'
Write-Output 'RANGE_206_CONTENT_RANGE_32_BYTES=PASS'
Write-Output 'HEAD_ZERO_BODY=PASS'
