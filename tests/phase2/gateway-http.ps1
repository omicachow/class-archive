[CmdletBinding()]
param()

# Real same-origin Gateway HTTP regression. This contacts only the local
# Piwigo runtime and persistent synthetic fixture accounts. It proves that
# aggregate values are computed after role/era filtering; it does not call
# Immich or claim an Immich web integration.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

$script:assertions = 0
$script:probes = 0

function Assert-True {
    param([Parameter(Mandatory = $true)][bool]$Condition, [Parameter(Mandatory = $true)][string]$Message)

    $script:assertions++
    if (-not $Condition) { throw "CLASS_ARCHIVE_GATEWAY_HTTP: $Message" }
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

function Get-PropertyValue {
    param($Object, [Parameter(Mandatory = $true)][string]$Name)

    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
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
    param([Parameter(Mandatory = $true)][Uri]$Uri, [Parameter(Mandatory = $true)][string]$Username, [Parameter(Mandatory = $true)][string]$Password)

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS -Uri $Uri -Session $session -Body @{
        method = 'pwg.session.login'; username = $Username; password = $Password
    }
    Assert-True ($response.stat -eq 'ok' -and [bool]$response.result) "Fixture login failed for $Username."
    return $session
}

function Invoke-Logout {
    param([Parameter(Mandatory = $true)][Uri]$Uri, [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session)

    try {
        $response = Invoke-WS -Uri $Uri -Session $Session -Body @{ method = 'pwg.session.logout' }
        if ($response.stat -ne 'ok') { throw 'logout rejected' }
    }
    catch {
        throw 'Synthetic fixture logout failed.'
    }
}

function Read-ResponseBody {
    param([Parameter(Mandatory = $true)][Net.HttpWebResponse]$Response)

    $stream = $Response.GetResponseStream()
    if ($null -eq $stream) { return '' }
    $buffer = New-Object byte[] 8192
    $memory = [IO.MemoryStream]::new()
    try {
        while ($true) {
            $read = $stream.Read($buffer, 0, $buffer.Length)
            if ($read -le 0) { break }
            if ($memory.Length + $read -gt 1048576) { throw 'Gateway response exceeded its bounded JSON size.' }
            $memory.Write($buffer, 0, $read)
        }
        return [Text.Encoding]::UTF8.GetString($memory.ToArray())
    }
    finally {
        [Array]::Clear($buffer, 0, $buffer.Length)
        $memory.Dispose()
        $stream.Dispose()
    }
}

function Invoke-Gateway {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        [hashtable]$Headers = @{}
    )

    $script:probes++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-Gateway-HTTP-Regression/1.0'
    if ($Method -eq 'POST') { $request.ContentLength = 0 }
    foreach ($entry in $Headers.GetEnumerator()) {
        $request.Headers[[string]$entry.Key] = [string]$entry.Value
    }

    $response = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw 'Gateway request failed without an HTTP response.' }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) { $headers[$key] = [string]$response.Headers[$key] }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            Headers = $headers
            Text = Read-ResponseBody -Response $response
        }
    }
    finally {
        if ($null -ne $response) { $response.Dispose() }
    }
}

function Invoke-GatewayMedia {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'HEAD')][string]$Method = 'GET',
        [switch]$Range32
    )

    $script:probes++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-Gateway-Media-Regression/1.0'
    if ($Range32) { $request.AddRange(0, 31) }

    $response = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw 'Gateway media request failed without an HTTP response.' }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) { $headers[$key] = [string]$response.Headers[$key] }
        $bytes = [byte[]]@()
        $stream = $response.GetResponseStream()
        if ($null -ne $stream) {
            try {
                $buffer = New-Object byte[] 64
                try {
                    $read = $stream.Read($buffer, 0, $buffer.Length)
                    if ($read -gt 0) {
                        $bytes = New-Object byte[] $read
                        [Array]::Copy($buffer, $bytes, $read)
                    }
                }
                finally { [Array]::Clear($buffer, 0, $buffer.Length) }
            }
            finally { $stream.Dispose() }
        }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            ContentRange = [string]$response.Headers['Content-Range']
            Headers = $headers
            Bytes = $bytes
        }
    }
    finally {
        if ($null -ne $response) { $response.Dispose() }
    }
}

function Assert-MediaAllow {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-True ($Response.Status -eq $Status) "$Label returned HTTP $($Response.Status), expected $Status."
    Assert-True ($Response.ContentType -like 'image/*') "$Label did not return image content."
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label response was cacheable."
    Assert-True (-not $Response.Headers.ContainsKey('X-Accel-Redirect')) "$Label exposed an internal X-Accel path."
    Assert-True (-not (($Response.Headers.Values -join "`n") -match 'action\.php\?id=|piwigo_image_id|immich_asset_id|/_data/|/upload/|/galleries/')) "$Label exposed a backend media identifier."
}

function Assert-ImageMagic {
    param([byte[]]$Bytes, [Parameter(Mandatory = $true)][string]$Label)

    $isJpeg = $Bytes.Length -ge 3 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xD8 -and $Bytes[2] -eq 0xFF
    $isPng = $Bytes.Length -ge 8 -and $Bytes[0] -eq 0x89 -and $Bytes[1] -eq 0x50 -and $Bytes[2] -eq 0x4E -and $Bytes[3] -eq 0x47
    $isGif = $Bytes.Length -ge 6 -and [Text.Encoding]::ASCII.GetString($Bytes, 0, 6) -in @('GIF87a', 'GIF89a')
    $isWebp = $Bytes.Length -ge 12 -and [Text.Encoding]::ASCII.GetString($Bytes, 0, 4) -eq 'RIFF' -and [Text.Encoding]::ASCII.GetString($Bytes, 8, 4) -eq 'WEBP'
    Assert-True ($isJpeg -or $isPng -or $isGif -or $isWebp) "$Label did not return image magic bytes."
}

function Assert-MediaDeny {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-True ($Response.Status -eq $Status) "$Label returned HTTP $($Response.Status), expected $Status."
    Assert-True ($Response.ContentType -notlike 'image/*') "$Label returned an image content type."
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label response was cacheable."
    Assert-True (-not ($Response.Bytes.Length -ge 3 -and $Response.Bytes[0] -eq 0xFF -and $Response.Bytes[1] -eq 0xD8 -and $Response.Bytes[2] -eq 0xFF)) "$Label leaked JPEG bytes."
    Assert-True (-not ($Response.Bytes.Length -ge 8 -and $Response.Bytes[0] -eq 0x89 -and $Response.Bytes[1] -eq 0x50 -and $Response.Bytes[2] -eq 0x4E -and $Response.Bytes[3] -eq 0x47)) "$Label leaked PNG bytes."
}

function Get-Json {
    param($Response, [Parameter(Mandatory = $true)][string]$Label)

    try { return ($Response.Text | ConvertFrom-Json) }
    catch { throw "$Label did not return bounded JSON." }
}

function Assert-PrivateJson {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-True ($Response.Status -eq $Status) "$Label returned HTTP $($Response.Status), expected $Status."
    Assert-True ($Response.ContentType -like 'application/json*') "$Label did not return JSON."
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label response was cacheable."
    Assert-True (-not $Response.Headers.ContainsKey('Access-Control-Allow-Origin')) "$Label unexpectedly enabled CORS."
    Assert-True ($Response.Text.Length -le 1048576) "$Label response exceeded the response bound."
}

function Assert-NoBackendLeak {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $true)][string]$Label)

    foreach ($needle in @(
        'piwigo_image_id', 'immich_asset_id', 'media_checksum', 'media_reference',
        'principal_id', 'account_id', 'seat_id', 'identity_id', 'user_id',
        '/upload/', '/galleries/', '/_data/', 'X-Accel-Redirect'
    )) {
        Assert-True (-not $Text.Contains($needle)) "$Label leaked $needle."
    }
}

function Get-Items {
    param($Payload)
    $items = Get-PropertyValue $Payload 'items'
    if ($null -eq $items) { return @() }
    return @($items)
}

function Invoke-FixtureProvision {
    param(
        [Parameter(Mandatory = $true)][string[]]$Compose,
        [Parameter(Mandatory = $true)][string]$Password
    )

    $result = @(& wsl.exe @($Compose + @(
        'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$Password",
        'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
    )) 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin $result) {
        throw 'Synthetic Gateway fixture provisioning failed.'
    }
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^[0-9]{1,5}$') { throw 'Invalid local HTTP port.' }
$baseUri = [Uri]("http://127.0.0.1:$port/")
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$fixturePassword = New-TransientSecret
$sessions = @{}
$adminLease = $null
$failure = $null
try {
    Invoke-FixtureProvision -Compose $compose -Password $fixturePassword
    $sessions['GUEST'] = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $sessions['CLASSMATE'] = Login-Role -Uri $wsUri -Username 'fixture-classmate' -Password $fixturePassword
    $sessions['TEACHER'] = Login-Role -Uri $wsUri -Username 'fixture-teacher' -Password $fixturePassword
    $sessions['FAMILY'] = Login-Role -Uri $wsUri -Username 'fixture-family' -Password $fixturePassword
    $sessions['ANONYMOUS'] = Login-Role -Uri $wsUri -Username 'fixture-anonymous' -Password $fixturePassword
    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $compose -AdminUsername $adminUsername
    $sessions['SYSTEM_ADMIN'] = $adminLease.Session

    $guestPhotos = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/photos')) -Session $sessions['GUEST']
    Assert-PrivateJson $guestPhotos 403 'guest photos'
    Assert-NoBackendLeak -Text $guestPhotos.Text -Label 'guest photos'

    $views = @{}
    foreach ($role in @('CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS', 'SYSTEM_ADMIN')) {
        $response = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/photos')) -Session $sessions[$role]
        Assert-PrivateJson $response 200 "$role photos"
        Assert-NoBackendLeak -Text $response.Text -Label "$role photos"
        $views[$role] = Get-Json -Response $response -Label "$role photos"
    }

    $classmateItems = Get-Items $views['CLASSMATE']
    $familyItems = Get-Items $views['FAMILY']
    Assert-True ($classmateItems.Count -gt 0) 'Classmate photos were unexpectedly empty.'
    Assert-True ($familyItems.Count -gt 0) 'Family HERITAGE photos were unexpectedly empty.'
    Assert-True ([int]$views['CLASSMATE'].total -gt [int]$views['FAMILY'].total) 'Family total did not exclude LIVING photos.'
    Assert-True ([int]$views['TEACHER'].total -eq [int]$views['CLASSMATE'].total) 'Teacher total differed from Classmate.'
    Assert-True ([int]$views['ANONYMOUS'].total -eq [int]$views['CLASSMATE'].total) 'Anonymous total differed from Classmate.'
    foreach ($item in $familyItems) {
        Assert-True ([string]$item.era -eq 'HERITAGE') 'Family list included a non-HERITAGE photo.'
        Assert-True ([string]$item.media.delivery -eq 'MEDIAGUARD_REQUIRED') 'Family item omitted MediaGuard delivery contract.'
        Assert-True ([string]$item.id -match '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') 'Family item did not use canonical UUID.'
    }
    foreach ($item in $classmateItems) {
        Assert-True ([string]$item.media.delivery -eq 'MEDIAGUARD_REQUIRED') 'Classmate item omitted MediaGuard delivery contract.'
    }

    $living = @($classmateItems | Where-Object { [string]$_.era -eq 'LIVING' } | Select-Object -First 1)
    $heritage = @($classmateItems | Where-Object { [string]$_.era -eq 'HERITAGE' } | Select-Object -First 1)
    Assert-True ($living.Count -eq 1) 'Synthetic fixture did not provide a LIVING Gateway candidate.'
    Assert-True ($heritage.Count -eq 1) 'Synthetic fixture did not provide a HERITAGE Gateway candidate.'
    $livingId = [string]$living[0].id
    $heritageId = [string]$heritage[0].id

    $familyHeritageThumbnail = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/thumbnail")) -Session $sessions['FAMILY']
    Assert-MediaAllow -Response $familyHeritageThumbnail -Status 200 -Label 'family canonical heritage thumbnail'
    Assert-ImageMagic -Bytes $familyHeritageThumbnail.Bytes -Label 'family canonical heritage thumbnail'
    $familyHeritagePreview = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/preview")) -Session $sessions['FAMILY']
    Assert-MediaAllow -Response $familyHeritagePreview -Status 200 -Label 'family canonical heritage preview'
    Assert-ImageMagic -Bytes $familyHeritagePreview.Bytes -Label 'family canonical heritage preview'
    $familyHeritageRange = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/thumbnail")) -Session $sessions['FAMILY'] -Range32
    Assert-MediaAllow -Response $familyHeritageRange -Status 206 -Label 'family canonical heritage thumbnail range'
    Assert-True ($familyHeritageRange.ContentRange -match '^bytes 0-31/\d+$') 'Family canonical thumbnail Range response did not preserve the requested byte range.'
    Assert-True ($familyHeritageRange.Bytes.Length -eq 32) 'Family canonical thumbnail Range response did not return exactly 32 bytes.'
    Assert-ImageMagic -Bytes $familyHeritageRange.Bytes -Label 'family canonical heritage thumbnail range'
    $familyHeritageHead = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/thumbnail")) -Session $sessions['FAMILY'] -Method HEAD
    Assert-MediaAllow -Response $familyHeritageHead -Status 200 -Label 'family canonical heritage thumbnail head'
    Assert-True ($familyHeritageHead.Bytes.Length -eq 0) 'Family canonical thumbnail HEAD response unexpectedly contained bytes.'
    $familyHeritageOriginal = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/original")) -Session $sessions['FAMILY']
    Assert-MediaDeny -Response $familyHeritageOriginal -Status 403 -Label 'family canonical heritage original'
    $familyLivingMedia = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$livingId/media/thumbnail")) -Session $sessions['FAMILY']
    Assert-MediaDeny -Response $familyLivingMedia -Status 404 -Label 'family canonical living thumbnail'
    $guestHeritageMedia = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$heritageId/media/thumbnail")) -Session $sessions['GUEST']
    Assert-MediaDeny -Response $guestHeritageMedia -Status 403 -Label 'guest canonical heritage thumbnail'
    $classmateLivingMedia = Invoke-GatewayMedia -Uri ([Uri]::new($baseUri, "api/photos/$livingId/media/preview")) -Session $sessions['CLASSMATE']
    Assert-MediaAllow -Response $classmateLivingMedia -Status 200 -Label 'classmate canonical living preview'
    Assert-ImageMagic -Bytes $classmateLivingMedia.Bytes -Label 'classmate canonical living preview'

    $familyHidden = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/photos/$livingId")) -Session $sessions['FAMILY']
    Assert-PrivateJson $familyHidden 404 'family hidden photo'
    Assert-True (-not $familyHidden.Text.Contains($livingId)) 'Family hidden photo response revealed the canonical id.'
    $unknown = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/photos/10000000-0000-4000-8000-000000000099')) -Session $sessions['FAMILY']
    Assert-PrivateJson $unknown 404 'family unknown photo'
    Assert-True ($familyHidden.Text -eq $unknown.Text) 'Hidden and unknown photo responses differed.'
    $familyHeritage = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/photos/$heritageId")) -Session $sessions['FAMILY']
    Assert-PrivateJson $familyHeritage 200 'family heritage photo'
    Assert-NoBackendLeak -Text $familyHeritage.Text -Label 'family heritage photo'
    $classmateLiving = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/photos/$livingId")) -Session $sessions['CLASSMATE']
    Assert-PrivateJson $classmateLiving 200 'classmate living photo'
    Assert-NoBackendLeak -Text $classmateLiving.Text -Label 'classmate living photo'
    $directPiwigoRoute = Invoke-Gateway -Uri ([Uri]::new($baseUri, "index.php?/class-archive-api/photos/$livingId")) -Session $sessions['CLASSMATE']
    Assert-PrivateJson $directPiwigoRoute 200 'direct Piwigo gateway route'

    foreach ($role in @('FAMILY', 'CLASSMATE')) {
        $timeline = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/timeline')) -Session $sessions[$role]
        Assert-PrivateJson $timeline 200 "$role timeline"
        Assert-NoBackendLeak -Text $timeline.Text -Label "$role timeline"
        $payload = Get-Json -Response $timeline -Label "$role timeline"
        Assert-True ([int]$payload.total -eq [int]$views[$role].total) "$role timeline total was not filtered before grouping."
        if ($role -eq 'FAMILY') { Assert-True (-not $timeline.Text.Contains($livingId)) 'Family timeline revealed a LIVING id.' }
    }

    $familyAlbumsResponse = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/albums')) -Session $sessions['FAMILY']
    Assert-PrivateJson $familyAlbumsResponse 200 'family albums'
    Assert-NoBackendLeak -Text $familyAlbumsResponse.Text -Label 'family albums'
    $familyAlbums = Get-Json -Response $familyAlbumsResponse -Label 'family albums'
    $familyAlbumNames = @((Get-Items $familyAlbums) | ForEach-Object { [string]$_.name })
    $familyPhotoAlbumNames = @($familyItems | ForEach-Object { @($_.albums) } | ForEach-Object { [string]$_ } | Sort-Object -Unique)
    Assert-True (@($familyAlbumNames | Where-Object { $_ -notin $familyPhotoAlbumNames }).Count -eq 0) 'Family album aggregation included a hidden-only album.'

    $heritageTitles = @($classmateItems | Where-Object { [string]$_.era -eq 'HERITAGE' } | ForEach-Object { [string]$_.title })
    $searchTerm = $null
    foreach ($candidate in $classmateItems) {
        $candidateTitle = [string]$candidate.title
        if ([string]$candidate.era -ne 'LIVING' -or [string]::IsNullOrWhiteSpace($candidateTitle)) { continue }
        $maxLength = [Math]::Min(32, $candidateTitle.Length)
        for ($length = $maxLength; $length -ge 1; $length--) {
            $term = $candidateTitle.Substring(0, $length)
            $presentInHeritage = @($heritageTitles | Where-Object {
                $_.IndexOf($term, [StringComparison]::OrdinalIgnoreCase) -ge 0
            }).Count -gt 0
            if (-not $presentInHeritage) {
                $searchTerm = $term
                break
            }
        }
        if ($null -ne $searchTerm) { break }
    }
    Assert-True ($null -ne $searchTerm) 'Synthetic fixture did not provide a bounded LIVING-only search term.'
    $query = [Uri]::EscapeDataString([string]$searchTerm)
    $classmateSearch = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/search?q=$query")) -Session $sessions['CLASSMATE']
    $familySearch = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/search?q=$query")) -Session $sessions['FAMILY']
    Assert-PrivateJson $classmateSearch 200 'classmate living search'
    Assert-PrivateJson $familySearch 200 'family living search'
    Assert-True ([int]((Get-Json $classmateSearch 'classmate living search').total) -ge 1) 'Classmate unique LIVING search found no result.'
    Assert-True ([int]((Get-Json $familySearch 'family living search').total) -eq 0) 'Family search count leaked a LIVING result.'
    Assert-True (-not $familySearch.Text.Contains($livingId)) 'Family search leaked a LIVING id.'

    foreach ($route in @('people', 'memories')) {
        foreach ($role in @('FAMILY', 'CLASSMATE')) {
            $response = Invoke-Gateway -Uri ([Uri]::new($baseUri, "api/$route")) -Session $sessions[$role]
            Assert-PrivateJson $response 200 "$role $route"
            $payload = Get-Json -Response $response -Label "$role $route"
            Assert-True (-not [bool]$payload.available -and [int]$payload.total -eq 0 -and @(Get-Items $payload).Count -eq 0) "$role $route faked an Immich response."
        }
    }

    foreach ($entry in @(
        [pscustomobject]@{ Label = 'unsupported method'; Uri = [Uri]::new($baseUri, 'api/photos'); Method = 'POST'; Session = $sessions['CLASSMATE']; Headers = @{}; Status = 405; Json = $true },
        [pscustomobject]@{ Label = 'duplicate query'; Uri = [Uri]::new($baseUri, 'api/search?q=a&q=b'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{}; Status = 400; Json = $true },
        [pscustomobject]@{ Label = 'unknown query'; Uri = [Uri]::new($baseUri, 'api/photos?unexpected=1'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{}; Status = 400; Json = $true },
        [pscustomobject]@{ Label = 'foreign origin'; Uri = [Uri]::new($baseUri, 'api/photos'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{ Origin = 'https://invalid.example' }; Status = 403; Json = $true },
        [pscustomobject]@{ Label = 'foreign fetch'; Uri = [Uri]::new($baseUri, 'api/photos'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{ 'Sec-Fetch-Site' = 'cross-site' }; Status = 403; Json = $true },
        [pscustomobject]@{ Label = 'root api'; Uri = [Uri]::new($baseUri, 'api'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{}; Status = 404; Json = $false },
        [pscustomobject]@{ Label = 'unknown route'; Uri = [Uri]::new($baseUri, 'api/not-a-route'); Method = 'GET'; Session = $sessions['CLASSMATE']; Headers = @{}; Status = 404; Json = $true }
    )) {
        $response = Invoke-Gateway -Uri $entry.Uri -Session $entry.Session -Method $entry.Method -Headers $entry.Headers
        if ([bool]$entry.Json) {
            Assert-PrivateJson $response $entry.Status $entry.Label
        }
        else {
            Assert-True ($response.Status -eq $entry.Status) "$($entry.Label) returned HTTP $($response.Status)."
            Assert-True ($response.ContentType -like 'text/plain*') "$($entry.Label) did not use the static deny response."
            Assert-True ($response.CacheControl -like '*no-store*') "$($entry.Label) response was cacheable."
        }
        Assert-NoBackendLeak -Text $response.Text -Label $entry.Label
    }

    $adminMe = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/me')) -Session $sessions['SYSTEM_ADMIN']
    Assert-PrivateJson $adminMe 200 'system admin me'
    Assert-True ([string](Get-Json $adminMe 'system admin me').role -eq 'SYSTEM_ADMIN') 'System admin Gateway role projection was incorrect.'
    $familyMe = Invoke-Gateway -Uri ([Uri]::new($baseUri, 'api/me')) -Session $sessions['FAMILY']
    Assert-PrivateJson $familyMe 200 'family me'
    Assert-True ([string](Get-Json $familyMe 'family me').role -eq 'FAMILY') 'Family Gateway role projection was incorrect.'
}
catch {
    $failure = $_
}
finally {
    $cleanupFailures = [Collections.Generic.List[string]]::new()
    foreach ($role in @('CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS')) {
        if ($sessions.ContainsKey($role)) {
            try { Invoke-Logout -Uri $wsUri -Session $sessions[$role] }
            catch { $cleanupFailures.Add("logout-$role") }
        }
    }
    if ($null -ne $adminLease) {
        try { Remove-ClassArchiveSystemAdminSession -Lease $adminLease }
        catch { $cleanupFailures.Add('admin-lease') }
    }
    try {
        # Rotate fixture credentials one final time; neither the prior nor the
        # replacement value is printed or persisted in the repository.
        $cleanupPassword = New-TransientSecret
        try { Invoke-FixtureProvision -Compose $compose -Password $cleanupPassword }
        finally { $cleanupPassword = $null }
    }
    catch { $cleanupFailures.Add('fixture-rotation') }
    $fixturePassword = $null
    if ($cleanupFailures.Count -gt 0 -and $null -eq $failure) {
        $failure = [InvalidOperationException]::new('Gateway test cleanup failed: ' + ($cleanupFailures -join ','))
    }
}

if ($null -ne $failure) { throw $failure }

Write-Output 'CLASS_ARCHIVE_GATEWAY_HTTP=PASS evidence=RUNTIME_TESTED'
Write-Output "HTTP_PROBES=$script:probes"
Write-Output "ASSERTIONS=$script:assertions"
Write-Output 'ROLE_ERA_AGGREGATION_FILTERING=PASS'
Write-Output 'GATEWAY_CANONICAL_MEDIA_MEDIAGUARD=PASS'
Write-Output 'IMMICH_ADAPTER=UNAVAILABLE_NOT_SIMULATED'
