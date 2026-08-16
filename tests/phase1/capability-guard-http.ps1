[CmdletBinding()]
param()

# Real Piwigo + MariaDB capability regression. It uses only persistent
# synthetic fixture accounts/photos, resets fixture passwords to a transient
# value, and removes every favorite it creates before returning.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:assertions = 0
$script:sessions = @{}
$script:imageId = 0
$script:favoriteNeedsCleanup = @{}

function Assert-True {
    param([Parameter(Mandatory = $true)][bool]$Condition, [Parameter(Mandatory = $true)][string]$Message)
    $script:assertions++
    if (-not $Condition) { throw "CAPABILITY_GUARD_HTTP: $Message" }
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
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Body
    )
    try {
        return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
    }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw }
        $response = [Net.HttpWebResponse]$_.Exception.Response
        try {
            $reader = [IO.StreamReader]::new($response.GetResponseStream(), [Text.Encoding]::UTF8)
            try { $json = $reader.ReadToEnd() } finally { $reader.Dispose() }
        } finally { $response.Dispose() }
        try { return $json | ConvertFrom-Json }
        catch { throw 'Piwigo WS error response was not generic JSON.' }
    }
}

function Login-Role {
    param([Uri]$Uri, [string]$Username, [string]$Password)
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS -Uri $Uri -Session $session -Body @{
        method = 'pwg.session.login'; username = $Username; password = $Password
    }
    Assert-True ($response.stat -eq 'ok' -and [bool]$response.result) "Login failed for $Username."
    return $session
}

function Assert-WsDenied {
    param($Response, [string]$Label, [int[]]$Errors = @(401, 403))
    $errorValue = Get-PropertyValue $Response 'err'
    $errorCode = if ($null -ne $errorValue) { [int]$errorValue } else { 0 }
    Assert-True ($Response.stat -eq 'fail' -and $errorCode -in $Errors) "$Label was not denied (stat=$($Response.stat), err=$errorCode)."
}

function Assert-WsAllowed {
    param($Response, [string]$Label)
    Assert-True ($Response.stat -eq 'ok') "$Label was unexpectedly denied."
}

function Flatten-Categories {
    param($Rows)
    $output = @()
    foreach ($row in @($Rows)) {
        if ($null -eq $row) { continue }
        $output += $row
        foreach ($property in @('categories', 'sub_categories', 'children')) {
            $children = Get-PropertyValue $row $property
            if ($null -ne $children) { $output += Flatten-Categories $children }
        }
    }
    return $output
}

function Get-PropertyValue {
    param($Object, [Parameter(Mandatory = $true)][string]$Name)
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Invoke-DirectHttp {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Form
    )
    $pairs = foreach ($key in ($Form.Keys | Sort-Object)) {
        [Net.WebUtility]::UrlEncode([string]$key) + '=' + [Net.WebUtility]::UrlEncode([string]$Form[$key])
    }
    $body = [Text.Encoding]::UTF8.GetBytes(($pairs -join '&'))
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'POST'
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.ContentType = 'application/x-www-form-urlencoded; charset=UTF-8'
    $request.ContentLength = $body.Length
    $request.UserAgent = 'ClassArchive-CapabilityGuard-Regression/1.0'
    $stream = $request.GetRequestStream()
    try { $stream.Write($body, 0, $body.Length) } finally { $stream.Dispose() }

    $response = $null
    try { $response = [Net.HttpWebResponse]$request.GetResponse() }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        $reader = [IO.StreamReader]::new($response.GetResponseStream(), [Text.Encoding]::UTF8)
        try { $text = $reader.ReadToEnd() } finally { $reader.Dispose() }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            Text = $text
        }
    } finally { $response.Dispose() }
}

function Assert-DirectCapabilityDenied {
    param($Response, [Parameter(Mandatory = $true)][string]$Label)
    Assert-True ($Response.Status -eq 403) "$Label was not HTTP 403."
    Assert-True ($Response.ContentType -like 'text/plain*') "$Label did not terminate at the capability boundary."
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label response was cacheable."
    Assert-True ($Response.Text -eq 'Access denied.') "$Label exposed a non-generic error."
}

function Get-ImageRows {
    param($ImagesValue)
    if ($null -eq $ImagesValue) { return @() }
    $content = Get-PropertyValue $ImagesValue '_content'
    if ($null -ne $content) { return @($content) }
    return @($ImagesValue)
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting $settings 'PIWIGO_ADMIN_USERNAME'
$baseUri = [Uri]("http://127.0.0.1:$port/")
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$fixturePassword = New-TransientSecret

$compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)
$provisionArgs = @($compose) + @(
    'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
    'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
)
$provisionOutput = @(& wsl.exe @provisionArgs 2>&1)
if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin $provisionOutput) {
    throw 'Synthetic capability fixture provisioning failed without exposing its output.'
}

$adminLease = $null
try {
    $script:sessions['CLASSMATE'] = Login-Role $wsUri 'fixture-classmate' $fixturePassword
    $script:sessions['TEACHER'] = Login-Role $wsUri 'fixture-teacher' $fixturePassword
    $script:sessions['FAMILY'] = Login-Role $wsUri 'fixture-family' $fixturePassword
    $script:sessions['ANONYMOUS'] = Login-Role $wsUri 'fixture-anonymous' $fixturePassword
    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $compose -AdminUsername $adminUsername
    $script:sessions['SYSTEM_ADMIN'] = $adminLease.Session

    $categoriesResponse = Invoke-WS $wsUri $script:sessions['CLASSMATE'] @{
        method = 'pwg.categories.getList'; recursive = 'true'; faked_by_community = 'false'
    }
    Assert-WsAllowed $categoriesResponse 'Classmate category read'
    $nestedCategories = Get-PropertyValue $categoriesResponse.result 'categories'
    $categoryValue = if ($null -ne $nestedCategories) { $nestedCategories } else { $categoriesResponse.result }
    $heritageAlbum = Flatten-Categories $categoryValue |
        Where-Object { $_.permalink -eq 'fixture-heritage-graduation' } |
        Select-Object -First 1
    Assert-True ($null -ne $heritageAlbum) 'Synthetic HERITAGE album was not found.'

    $imagesResponse = Invoke-WS $wsUri $script:sessions['CLASSMATE'] @{
        method = 'pwg.categories.getImages'; cat_id = [string]$heritageAlbum.id; recursive = 'false'; per_page = '10'
    }
    Assert-WsAllowed $imagesResponse 'Classmate image read'
    $imageRows = @(Get-ImageRows (Get-PropertyValue $imagesResponse.result 'images'))
    Assert-True ($imageRows.Count -gt 0) 'Synthetic HERITAGE image was not found.'
    $image = $imageRows[0]
    $script:imageId = [int]$image.id
    Assert-True ($script:imageId -gt 0) 'Synthetic image id was invalid.'

    # Family: direct Piwigo comment/rating/upload/album creation are forbidden.
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['FAMILY'] @{
        method='pwg.images.addComment'; image_id=[string]$script:imageId; author='Synthetic Family'; content='denied'; key='invalid'
    }) 'Family WS comment' @(403)
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['FAMILY'] @{
        method='pwg.images.rate'; image_id=[string]$script:imageId; rate='5'
    }) 'Family WS rating' @(403)
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['FAMILY'] @{
        method='pwg.images.addSimple'; category=[string]$heritageAlbum.id
    }) 'Family WS upload'
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['FAMILY'] @{
        method='pwg.categories.add'; name='CITEST forbidden family album'
    }) 'Family WS album creation'

    # Comment gate off/on behavior and presentation non-linkability are proven
    # by anonymous-presenter-http.ps1. This runner covers capabilities that are
    # unconditionally forbidden regardless of persisted presenter attestation.
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['ANONYMOUS'] @{
        method='pwg.images.rate'; image_id=[string]$script:imageId; rate='5'
    }) 'Anonymous WS rating' @(403)
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['ANONYMOUS'] @{
        method='pwg.images.addSimple'; category=[string]$heritageAlbum.id
    }) 'Anonymous WS upload'
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['ANONYMOUS'] @{
        method='pwg.categories.add'; name='CITEST forbidden anonymous album'
    }) 'Anonymous WS album creation'
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['ANONYMOUS'] @{
        method='pwg.users.favorites.add'; image_id=[string]$script:imageId
    }) 'Anonymous WS favorite' @(403)
    Assert-WsDenied (Invoke-WS $wsUri $script:sessions['ANONYMOUS'] @{
        method='pwg.users.preferences.set'; param='capability_guard_probe'; value='denied'
    }) 'Anonymous WS preference mutation' @(403)

    # A benign, reversible member write proves the guard does not collapse
    # CLASSMATE/TEACHER into the restricted roles. Upload/album contribution
    # still depends on the deliberately inactive Community plugin.
    foreach ($role in @('CLASSMATE', 'TEACHER', 'FAMILY')) {
        $favoriteList = Invoke-WS $wsUri $script:sessions[$role] @{
            method='pwg.users.favorites.getList'; per_page='100'; page='0'
        }
        Assert-WsAllowed $favoriteList "$role favorite baseline read"
        $favoriteRows = @(Get-ImageRows (Get-PropertyValue $favoriteList.result 'images'))
        $wasPresent = @($favoriteRows | Where-Object { [int]$_.id -eq $script:imageId }).Count -gt 0
        $script:favoriteNeedsCleanup[$role] = -not $wasPresent
        Assert-WsAllowed (Invoke-WS $wsUri $script:sessions[$role] @{
            method='pwg.users.favorites.add'; image_id=[string]$script:imageId
        }) "$role favorite add"
        if (-not $wasPresent) {
            Assert-WsAllowed (Invoke-WS $wsUri $script:sessions[$role] @{
                method='pwg.users.favorites.remove'; image_id=[string]$script:imageId
            }) "$role favorite remove"
            $script:favoriteNeedsCleanup[$role] = $false
        }
    }

    # SYSTEM_ADMIN is an independent principal and remains subject to Core's
    # webmaster checks, not a member-role projection. A real admin-only Core
    # read proves that member capability filtering did not downgrade it.
    $adminStatus = Invoke-WS $wsUri $script:sessions['SYSTEM_ADMIN'] @{ method='pwg.session.getStatus' }
    Assert-WsAllowed $adminStatus 'System admin session status'
    Assert-WsAllowed (Invoke-WS $wsUri $script:sessions['SYSTEM_ADMIN'] @{
        method='pwg.categories.getAdminList'; recursive='true'
    }) 'System admin Core admin-only album read'

    # picture.php has non-WS write handlers. A valid visible photo route with
    # deliberately invalid Core form keys must still be rejected by our role
    # guard before Core comment/rating processing.
    $pageUrl = Get-PropertyValue $image 'page_url'
    $pictureReference = if ($null -ne $pageUrl -and -not [string]::IsNullOrWhiteSpace([string]$pageUrl)) {
        [Net.WebUtility]::HtmlDecode([string]$pageUrl)
    } else {
        "picture.php?/$($script:imageId)"
    }
    $pictureCandidate = [Uri]$pictureReference
    $pictureUri = if ($pictureCandidate.IsAbsoluteUri) {
        [Uri]::new($baseUri, $pictureCandidate.PathAndQuery)
    } else {
        [Uri]::new($baseUri, $pictureReference)
    }
    foreach ($role in @('FAMILY', 'ANONYMOUS')) {
        Assert-WsAllowed (Invoke-WS $wsUri $script:sessions[$role] @{ method='pwg.session.getStatus' }) "$role session before direct picture probes"
    }
    $rateBuilder = [UriBuilder]::new($pictureUri)
    $rateBuilder.Query = if ([string]::IsNullOrEmpty($pictureUri.Query)) { 'action=rate' } else { $pictureUri.Query.TrimStart('?') + '&action=rate' }
    Assert-DirectCapabilityDenied (Invoke-DirectHttp $rateBuilder.Uri $script:sessions['FAMILY'] @{ rate='5' }) 'Family direct picture rating'
    Assert-DirectCapabilityDenied (Invoke-DirectHttp $pictureUri $script:sessions['FAMILY'] @{ content='denied'; key='invalid' }) 'Family direct picture comment'
    Assert-DirectCapabilityDenied (Invoke-DirectHttp $rateBuilder.Uri $script:sessions['ANONYMOUS'] @{ rate='5' }) 'Anonymous direct picture rating'

    Write-Output "CLASS_IDENTITY_CAPABILITY_HTTP=PASS assertions=$script:assertions"
    Write-Output 'FAMILY_COMMENT_RATE_UPLOAD_ALBUM=DENY'
    Write-Output 'ANONYMOUS_RESTRICTED_CAPABILITIES=DENY'
    Write-Output 'ANONYMOUS_COMMENT_GATE=VERIFIED_BY_ANONYMOUS_PRESENTER_HTTP'
    Write-Output 'CLASSMATE_TEACHER_CONTRIBUTION_GUARD=ALLOW_CORE_POLICY_STILL_REQUIRED'
    Write-Output 'SYSTEM_ADMIN_INDEPENDENT_CAPABILITY_PATH=PASS'
    Write-Output 'COMMUNITY_UPLOAD_RUNTIME=NOT_TESTED_PLUGIN_INACTIVE'
}
finally {
    # Best-effort, target-specific cleanup. Never delete photos or broad data.
    foreach ($role in @('CLASSMATE', 'TEACHER', 'FAMILY')) {
        if (
            $script:imageId -gt 0 -and
            $script:sessions.ContainsKey($role) -and
            $script:favoriteNeedsCleanup.ContainsKey($role) -and
            [bool]$script:favoriteNeedsCleanup[$role]
        ) {
            try {
                Invoke-WS $wsUri $script:sessions[$role] @{
                    method='pwg.users.favorites.remove'; image_id=[string]$script:imageId
                } | Out-Null
            } catch {}
        }
    }
    if ($null -ne $adminLease) {
        Remove-ClassArchiveSystemAdminSession -Lease $adminLease
    }
    $fixturePassword = $null
}
