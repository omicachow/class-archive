[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$UploadPath,

    [Parameter(Mandatory = $true)]
    [string]$ImagePath
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env. Run infra\scripts\init-dev-env.ps1 first.'
}

$environment = @{}
foreach ($line in [IO.File]::ReadAllLines($envPath)) {
    $trimmed = $line.Trim()
    if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) {
        continue
    }
    $separator = $trimmed.IndexOf('=')
    if ($separator -lt 1) {
        continue
    }
    $environment[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
}

foreach ($requiredName in @('CLASS_ARCHIVE_BASE_URL', 'HUMHUB_ADMIN_USERNAME', 'HUMHUB_ADMIN_PASSWORD')) {
    if (-not $environment.ContainsKey($requiredName) -or [string]::IsNullOrWhiteSpace($environment[$requiredName])) {
        throw "Missing $requiredName in .env."
    }
}

$baseUri = [Uri]$environment['CLASS_ARCHIVE_BASE_URL']
if ($baseUri.Scheme -notin @('http', 'https') -or -not $baseUri.IsLoopback -or $baseUri.UserInfo) {
    throw 'This smoke helper is intentionally restricted to a loopback Class Archive URL.'
}
if (-not $UploadPath.StartsWith('/') -or $UploadPath.StartsWith('//') -or $UploadPath.Contains('\')) {
    throw 'UploadPath must be an application-relative path beginning with /.'
}

function Assert-SameLoopbackOrigin {
    param(
        [Uri]$Candidate,
        [Uri]$ExpectedBase,
        [string]$Context
    )

    if (
        -not $Candidate.IsAbsoluteUri `
        -or $Candidate.Scheme -notin @('http', 'https') `
        -or -not $Candidate.IsLoopback `
        -or $Candidate.UserInfo `
        -or $Candidate.Scheme -ne $ExpectedBase.Scheme `
        -or $Candidate.DnsSafeHost -ne $ExpectedBase.DnsSafeHost `
        -or $Candidate.Port -ne $ExpectedBase.Port
    ) {
        throw "$Context escaped the configured loopback origin."
    }
}

function Resolve-LocalUri {
    param(
        [Uri]$BaseUri,
        [string]$Path,
        [string]$Context
    )

    $resolved = [Uri]::new($BaseUri, $Path)
    Assert-SameLoopbackOrigin -Candidate $resolved -ExpectedBase $BaseUri -Context $Context
    return $resolved
}

$resolvedImage = (Resolve-Path -LiteralPath $ImagePath).Path
$imageInfo = Get-Item -LiteralPath $resolvedImage
if ($imageInfo.Extension.ToLowerInvariant() -ne '.png') {
    throw 'The Phase 0 smoke helper accepts generated PNG fixtures only.'
}
if ($imageInfo.Length -lt 8 -or $imageInfo.Length -gt 10MB) {
    throw 'The PNG fixture must be between 8 bytes and 10 MiB.'
}

$signatureStream = [IO.File]::OpenRead($resolvedImage)
try {
    $signature = New-Object byte[] 8
    if ($signatureStream.Read($signature, 0, $signature.Length) -ne $signature.Length) {
        throw 'Cannot read the PNG signature.'
    }
}
finally {
    $signatureStream.Dispose()
}
$expectedPngSignature = [byte[]](137, 80, 78, 71, 13, 10, 26, 10)
if ([Convert]::ToBase64String($signature) -ne [Convert]::ToBase64String($expectedPngSignature)) {
    throw 'The selected fixture is not a PNG file.'
}

Add-Type -AssemblyName System.Net.Http

$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $false
$handler.UseCookies = $true
$handler.UseProxy = $false
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds(60)

function Send-LocalRequest {
    param(
        [System.Net.Http.HttpClient]$Client,
        [System.Net.Http.HttpRequestMessage]$Request,
        [Uri]$BaseUri,
        [string]$Context
    )

    $currentRequest = $Request
    $ownsCurrentRequest = $false
    for ($redirectCount = 0; $redirectCount -le 5; $redirectCount++) {
        $requestMethod = $currentRequest.Method
        try {
            $response = $Client.SendAsync($currentRequest).GetAwaiter().GetResult()
        }
        finally {
            if ($ownsCurrentRequest) {
                $currentRequest.Dispose()
            }
        }

        $status = [int]$response.StatusCode
        if ($status -notin @(301, 302, 303, 307, 308)) {
            Assert-SameLoopbackOrigin -Candidate $response.RequestMessage.RequestUri -ExpectedBase $BaseUri -Context $Context
            return $response
        }

        if ($redirectCount -eq 5) {
            $response.Dispose()
            throw "$Context exceeded five redirects."
        }

        $location = $response.Headers.Location
        if ($null -eq $location) {
            $response.Dispose()
            throw "$Context returned a redirect without Location."
        }

        $redirectUri = if ($location.IsAbsoluteUri) {
            $location
        } else {
            [Uri]::new($response.RequestMessage.RequestUri, $location)
        }
        Assert-SameLoopbackOrigin -Candidate $redirectUri -ExpectedBase $BaseUri -Context "$Context redirect"

        if ($status -in @(307, 308) -and $requestMethod -notin @([System.Net.Http.HttpMethod]::Get, [System.Net.Http.HttpMethod]::Head)) {
            $response.Dispose()
            throw "$Context refused a redirect that would replay a request body."
        }

        $redirectMethod = if ($requestMethod -eq [System.Net.Http.HttpMethod]::Head) {
            [System.Net.Http.HttpMethod]::Head
        } else {
            [System.Net.Http.HttpMethod]::Get
        }
        $response.Dispose()
        $currentRequest = [System.Net.Http.HttpRequestMessage]::new($redirectMethod, $redirectUri)
        $ownsCurrentRequest = $true
    }
}

function Read-ResponseBody {
    param(
        [System.Net.Http.HttpResponseMessage]$Response,
        [string]$Context
    )

    $body = $Response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
    if (-not $Response.IsSuccessStatusCode) {
        throw "$Context failed with HTTP $([int]$Response.StatusCode)."
    }
    return $body
}

function Get-CsrfToken {
    param([string]$Html)

    $metaMatch = [regex]::Match(
        $Html,
        '<meta\s+name=["'']csrf-token["'']\s+content=["'']([^"'']+)["'']',
        [Text.RegularExpressions.RegexOptions]::IgnoreCase
    )
    if ($metaMatch.Success) {
        return [Net.WebUtility]::HtmlDecode($metaMatch.Groups[1].Value)
    }

    $inputMatch = [regex]::Match(
        $Html,
        '<input[^>]+name=["'']_csrf["''][^>]+value=["'']([^"'']+)["'']',
        [Text.RegularExpressions.RegexOptions]::IgnoreCase
    )
    if ($inputMatch.Success) {
        return [Net.WebUtility]::HtmlDecode($inputMatch.Groups[1].Value)
    }

    throw 'HumHub CSRF token was not found.'
}

try {
    $loginUri = Resolve-LocalUri -BaseUri $baseUri -Path '/user/auth/login' -Context 'Login URI'
    $loginPageRequest = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Get, $loginUri)
    try {
        $loginPageResponse = Send-LocalRequest -Client $client -Request $loginPageRequest -BaseUri $baseUri -Context 'Login page request'
        try {
            $loginPage = Read-ResponseBody -Response $loginPageResponse -Context 'Login page request'
            $loginCsrf = Get-CsrfToken -Html $loginPage
        }
        finally {
            $loginPageResponse.Dispose()
        }
    }
    finally {
        $loginPageRequest.Dispose()
    }

    $loginFields = [System.Collections.Generic.Dictionary[string, string]]::new()
    $loginFields['_csrf'] = $loginCsrf
    $loginFields['Login[username]'] = $environment['HUMHUB_ADMIN_USERNAME']
    $loginFields['Login[password]'] = $environment['HUMHUB_ADMIN_PASSWORD']
    $loginFields['Login[rememberMe]'] = '0'
    $loginContent = [System.Net.Http.FormUrlEncodedContent]::new($loginFields)
    $loginRequest = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Post, $loginUri)
    $loginRequest.Content = $loginContent
    try {
        $loginResponse = Send-LocalRequest -Client $client -Request $loginRequest -BaseUri $baseUri -Context 'Local administrator login'
        try {
            $loginResult = Read-ResponseBody -Response $loginResponse -Context 'Local administrator login'
            $loginResultUri = $loginResponse.RequestMessage.RequestUri
        }
        finally {
            $loginResponse.Dispose()
        }
    }
    finally {
        $loginRequest.Dispose()
        $loginContent.Dispose()
    }

    if ($loginResultUri.AbsolutePath -eq '/user/auth/login' -or $loginResult.Contains('id="login_username"')) {
        throw 'Local administrator login did not complete. Check the ignored .env credentials or 2FA policy.'
    }

    $galleryPath = $UploadPath -replace '/upload(?=\?|$)', ''
    if ($galleryPath -eq $UploadPath) {
        throw 'UploadPath must end its path component with /upload.'
    }
    $galleryPageUri = Resolve-LocalUri -BaseUri $baseUri -Path $galleryPath -Context 'Gallery page URI'
    $galleryRequest = [System.Net.Http.HttpRequestMessage]::new([System.Net.Http.HttpMethod]::Get, $galleryPageUri)
    try {
        $galleryResponse = Send-LocalRequest -Client $client -Request $galleryRequest -BaseUri $baseUri -Context 'Gallery page request'
        try {
            $galleryPage = Read-ResponseBody -Response $galleryResponse -Context 'Gallery page request'
            $galleryCsrf = Get-CsrfToken -Html $galleryPage
        }
        finally {
            $galleryResponse.Dispose()
        }
    }
    finally {
        $galleryRequest.Dispose()
    }

    $multipart = [System.Net.Http.MultipartFormDataContent]::new()
    $fileStream = [IO.File]::OpenRead($resolvedImage)
    $fileContent = [System.Net.Http.StreamContent]::new($fileStream)
    $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::new('image/png')
    $multipart.Add($fileContent, 'files[]', $imageInfo.Name)

    $uploadUri = Resolve-LocalUri -BaseUri $baseUri -Path $UploadPath -Context 'Gallery upload URI'
    $request = [System.Net.Http.HttpRequestMessage]::new(
        [System.Net.Http.HttpMethod]::Post,
        $uploadUri
    )
    $request.Headers.Add('X-Requested-With', 'XMLHttpRequest')
    $request.Headers.Add('X-CSRF-Token', $galleryCsrf)
    $request.Content = $multipart
    try {
        $uploadResponse = Send-LocalRequest -Client $client -Request $request -BaseUri $baseUri -Context 'Gallery upload'
        try {
            $uploadBody = Read-ResponseBody -Response $uploadResponse -Context 'Gallery upload'
        }
        finally {
            $uploadResponse.Dispose()
        }
    }
    finally {
        $request.Dispose()
        $multipart.Dispose()
        $fileContent.Dispose()
        $fileStream.Dispose()
    }

    $result = $uploadBody | ConvertFrom-Json
    if ($null -eq $result.files -or $result.files.Count -ne 1 -or $result.files[0].error) {
        throw 'Gallery returned an invalid or failed upload result.'
    }

    Write-Output "Gallery smoke upload passed: $($imageInfo.Name)"
} finally {
    $client.Dispose()
    $handler.Dispose()
}
