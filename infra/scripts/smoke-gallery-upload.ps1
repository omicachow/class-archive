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
if (-not $baseUri.IsLoopback) {
    throw 'This smoke helper is intentionally restricted to a loopback Class Archive URL.'
}
if (-not $UploadPath.StartsWith('/')) {
    throw 'UploadPath must be an application-relative path beginning with /.'
}

$resolvedImage = (Resolve-Path -LiteralPath $ImagePath).Path
$imageInfo = Get-Item -LiteralPath $resolvedImage
if ($imageInfo.Extension.ToLowerInvariant() -ne '.png') {
    throw 'The Phase 0 smoke helper accepts generated PNG fixtures only.'
}

Add-Type -AssemblyName System.Net.Http

$handler = [System.Net.Http.HttpClientHandler]::new()
$handler.AllowAutoRedirect = $true
$handler.UseCookies = $true
$handler.UseProxy = $false
$client = [System.Net.Http.HttpClient]::new($handler)
$client.Timeout = [TimeSpan]::FromSeconds(60)

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
    $loginUri = [Uri]::new($baseUri, '/user/auth/login')
    $loginPageResponse = $client.GetAsync($loginUri).GetAwaiter().GetResult()
    $loginPage = Read-ResponseBody -Response $loginPageResponse -Context 'Login page request'
    $loginCsrf = Get-CsrfToken -Html $loginPage

    $loginFields = [System.Collections.Generic.Dictionary[string, string]]::new()
    $loginFields['_csrf'] = $loginCsrf
    $loginFields['Login[username]'] = $environment['HUMHUB_ADMIN_USERNAME']
    $loginFields['Login[password]'] = $environment['HUMHUB_ADMIN_PASSWORD']
    $loginFields['Login[rememberMe]'] = '0'
    $loginContent = [System.Net.Http.FormUrlEncodedContent]::new($loginFields)
    try {
        $loginResponse = $client.PostAsync($loginUri, $loginContent).GetAwaiter().GetResult()
        $loginResult = Read-ResponseBody -Response $loginResponse -Context 'Local administrator login'
    } finally {
        $loginContent.Dispose()
    }

    if ($loginResponse.RequestMessage.RequestUri.AbsolutePath -eq '/user/auth/login' -or $loginResult.Contains('id="login_username"')) {
        throw 'Local administrator login did not complete. Check the ignored .env credentials or 2FA policy.'
    }

    $galleryPageUri = [Uri]::new($baseUri, $UploadPath.Replace('/upload', ''))
    $galleryResponse = $client.GetAsync($galleryPageUri).GetAwaiter().GetResult()
    $galleryPage = Read-ResponseBody -Response $galleryResponse -Context 'Gallery page request'
    $galleryCsrf = Get-CsrfToken -Html $galleryPage

    $multipart = [System.Net.Http.MultipartFormDataContent]::new()
    $fileStream = [IO.File]::OpenRead($resolvedImage)
    $fileContent = [System.Net.Http.StreamContent]::new($fileStream)
    $fileContent.Headers.ContentType = [System.Net.Http.Headers.MediaTypeHeaderValue]::new('image/png')
    $multipart.Add($fileContent, 'files[]', $imageInfo.Name)

    $request = [System.Net.Http.HttpRequestMessage]::new(
        [System.Net.Http.HttpMethod]::Post,
        [Uri]::new($baseUri, $UploadPath)
    )
    $request.Headers.Add('X-Requested-With', 'XMLHttpRequest')
    $request.Headers.Add('X-CSRF-Token', $galleryCsrf)
    $request.Content = $multipart
    try {
        $uploadResponse = $client.SendAsync($request).GetAwaiter().GetResult()
        $uploadBody = Read-ResponseBody -Response $uploadResponse -Context 'Gallery upload'
    } finally {
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
