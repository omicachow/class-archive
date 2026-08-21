[CmdletBinding()]
param(
    [switch]$KeepRunning
)

# Real localhost-only HTTP regression for the Phase 2 Web compatibility
# boundary. This is RUNTIME_TESTED evidence for the official static Immich Web
# build plus the Class Archive Gateway; it is not an Immich-auth or browser E2E
# claim. It creates no Immich user, asset, library, database row or media copy.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$piwigoEnvPath = Join-Path $projectRoot '.env.piwigo'
$spikeEnvPath = Join-Path $projectRoot 'infra\immich-spike\.env'
$spikeComposePath = Join-Path $projectRoot 'infra\immich-spike\docker-compose.yml'
$upstreamLockPath = Join-Path $projectRoot 'infra\immich-spike\immich-upstream.lock.json'
$compatContainer = 'class-archive-immich-spike-immich-web-compat-1'
$piwigoContainer = 'class_archive_piwigo-piwigo-1'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

$script:assertions = 0
$script:probes = 0

function Assert-True {
    param([Parameter(Mandatory = $true)][bool]$Condition, [Parameter(Mandatory = $true)][string]$Message)

    $script:assertions++
    if (-not $Condition) { throw "IMMICH_WEB_COMPAT_HTTP: $Message" }
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)

    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'invalid_local_env_syntax' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function Require-Setting {
    param([Parameter(Mandatory = $true)][hashtable]$Settings, [Parameter(Mandatory = $true)][string]$Key)

    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Key])) {
        throw "missing_local_setting_$Key"
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

function Read-ResponseBytes {
    param(
        [Parameter(Mandatory = $true)][Net.HttpWebResponse]$Response,
        [Parameter(Mandatory = $true)][int]$MaximumBytes,
        [switch]$PrefixOnly
    )

    $stream = $Response.GetResponseStream()
    if ($null -eq $stream) { return [byte[]]@() }
    $buffer = New-Object byte[] 8192
    $memory = [IO.MemoryStream]::new()
    try {
        while ($memory.Length -lt $MaximumBytes) {
            $remaining = $MaximumBytes - [int]$memory.Length
            $read = $stream.Read($buffer, 0, [Math]::Min($buffer.Length, $remaining))
            if ($read -le 0) { break }
            $memory.Write($buffer, 0, $read)
        }
        if (-not $PrefixOnly -and $memory.Length -eq $MaximumBytes -and $stream.ReadByte() -ne -1) {
            throw 'compat_response_exceeded_bound'
        }
        return $memory.ToArray()
    }
    finally {
        [Array]::Clear($buffer, 0, $buffer.Length)
        $memory.Dispose()
        $stream.Dispose()
    }
}

function Invoke-Http {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'HEAD', 'POST', 'OPTIONS')][string]$Method = 'GET',
        [hashtable]$Headers = @{},
        [string]$JsonBody = '',
        [int]$MaximumBytes = 1048576,
        [switch]$PrefixOnly,
        [switch]$Range32
    )

    $script:probes++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.AllowAutoRedirect = $false
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-Immich-Web-Compat-HTTP/1.0'
    if ($null -ne $Session) { $request.CookieContainer = $Session.Cookies }
    if ($Range32) { $request.AddRange(0, 31) }
    foreach ($entry in $Headers.GetEnumerator()) {
        $request.Headers[[string]$entry.Key] = [string]$entry.Value
    }
    if ($Method -eq 'POST') {
        $payload = [Text.Encoding]::UTF8.GetBytes($JsonBody)
        try {
            $request.ContentType = 'application/json; charset=utf-8'
            $request.ContentLength = $payload.Length
            $stream = $request.GetRequestStream()
            try { $stream.Write($payload, 0, $payload.Length) }
            finally { $stream.Dispose() }
        }
        finally { [Array]::Clear($payload, 0, $payload.Length) }
    }

    $response = $null
    try {
        $response = [Net.HttpWebResponse]$request.GetResponse()
    }
    catch [Net.WebException] {
        if ($null -eq $_.Exception.Response) { throw 'compat_request_no_http_response' }
        $response = [Net.HttpWebResponse]$_.Exception.Response
    }
    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) { $headers[$key] = [string]$response.Headers[$key] }
        $bytes = if ($Method -eq 'HEAD') { [byte[]]@() } else { Read-ResponseBytes -Response $response -MaximumBytes $MaximumBytes -PrefixOnly:$PrefixOnly }
        # An empty byte array emitted from a PowerShell function is represented
        # as no pipeline output. Normalize redirect/HEAD bodies before text
        # decoding so that a legitimate empty deny body remains testable.
        if ($null -eq $bytes) { $bytes = [byte[]]@() }
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            ContentType = [string]$response.ContentType
            CacheControl = [string]$response.Headers['Cache-Control']
            ContentRange = [string]$response.Headers['Content-Range']
            Location = [string]$response.Headers['Location']
            Headers = $headers
            Bytes = $bytes
            Text = [Text.Encoding]::UTF8.GetString($bytes)
        }
    }
    finally {
        if ($null -ne $response) { $response.Dispose() }
    }
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
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password
    )

    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS -Uri $Uri -Session $session -Body @{
        method = 'pwg.session.login'; username = $Username; password = $Password
    }
    Assert-True ($response.stat -eq 'ok' -and [bool]$response.result) "fixture_login_failed_$Username"
    return $session
}

function Invoke-Logout {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )

    $response = Invoke-WS -Uri $Uri -Session $Session -Body @{ method = 'pwg.session.logout' }
    if ($response.stat -ne 'ok') { throw 'fixture_logout_rejected' }
}

function Invoke-FixtureProvision {
    param(
        [Parameter(Mandatory = $true)][string[]]$Compose,
        [Parameter(Mandatory = $true)][string]$Password
    )

    $output = @(& wsl.exe @($Compose + @(
        'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$Password",
        'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
    )) 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin $output) {
        throw 'fixture_provisioning_failed'
    }
}

function Invoke-CompatCompose {
    param([Parameter(Mandatory = $true)][string[]]$Compose, [Parameter(Mandatory = $true)][string[]]$Arguments)

    # Compose v5 writes ordinary create/recreate progress to stderr. Its exit
    # status, rather than the progress stream, is the authority. Temporarily
    # lower ErrorActionPreference while capturing that native stream.
    $previousPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& wsl.exe @($Compose + $Arguments) 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) { throw 'immich_web_compat_compose_failed' }
    return [string]::Join("`n", $output)
}

function Invoke-UbuntuDocker {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)

    $output = @(& wsl.exe -d Ubuntu --exec docker @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { throw 'immich_web_compat_docker_failed' }
    return [string]::Join("`n", $output)
}

function Wait-CompatHealthy {
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        $state = (Invoke-UbuntuDocker -Arguments @('inspect', '--format', '{{.State.Health.Status}}', $compatContainer)).Trim()
        if ($state -eq 'healthy') { return }
        Start-Sleep -Seconds 1
    }
    throw 'immich_web_compat_not_healthy'
}

function Assert-PrivateResponse {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-True ($Response.Status -eq $Status) "$Label returned HTTP $($Response.Status)"
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label response was cacheable"
    Assert-True (-not $Response.Headers.ContainsKey('Access-Control-Allow-Origin')) "$Label enabled CORS"
}

function Assert-Json {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-PrivateResponse -Response $Response -Status $Status -Label $Label
    Assert-True ($Response.ContentType -like 'application/json*') "$Label did not return JSON"
    try { return ($Response.Text | ConvertFrom-Json -ErrorAction Stop) }
    catch { throw "$Label returned invalid JSON" }
}

function Assert-NoBackendLeak {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $true)][string]$Label)

    foreach ($needle in @(
        'piwigo_image_id', 'immich_asset_id', 'media_checksum', 'media_reference',
        'principal_id', 'account_id', 'seat_id', 'identity_id', 'user_id',
        '/upload/', '/galleries/', '/_data/', 'X-Accel-Redirect'
    )) {
        Assert-True (-not $Text.Contains($needle)) "$Label leaked $needle"
    }
}

function Assert-ImageMagic {
    param([byte[]]$Bytes, [Parameter(Mandatory = $true)][string]$Label)

    $isJpeg = $Bytes.Length -ge 3 -and $Bytes[0] -eq 0xFF -and $Bytes[1] -eq 0xD8 -and $Bytes[2] -eq 0xFF
    $isPng = $Bytes.Length -ge 8 -and $Bytes[0] -eq 0x89 -and $Bytes[1] -eq 0x50 -and $Bytes[2] -eq 0x4E -and $Bytes[3] -eq 0x47
    $isGif = $Bytes.Length -ge 6 -and [Text.Encoding]::ASCII.GetString($Bytes, 0, 6) -in @('GIF87a', 'GIF89a')
    $isWebp = $Bytes.Length -ge 12 -and [Text.Encoding]::ASCII.GetString($Bytes, 0, 4) -eq 'RIFF' -and [Text.Encoding]::ASCII.GetString($Bytes, 8, 4) -eq 'WEBP'
    Assert-True ($isJpeg -or $isPng -or $isGif -or $isWebp) "$Label did not return image magic"
}

function Assert-MediaAllow {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-PrivateResponse -Response $Response -Status $Status -Label $Label
    Assert-True ($Response.ContentType -like 'image/*') "$Label did not return image content"
}

function Assert-MediaDeny {
    param($Response, [Parameter(Mandatory = $true)][int]$Status, [Parameter(Mandatory = $true)][string]$Label)

    Assert-PrivateResponse -Response $Response -Status $Status -Label $Label
    Assert-True ($Response.ContentType -notlike 'image/*') "$Label returned image content"
    $jpeg = $Response.Bytes.Length -ge 3 -and $Response.Bytes[0] -eq 0xFF -and $Response.Bytes[1] -eq 0xD8 -and $Response.Bytes[2] -eq 0xFF
    $png = $Response.Bytes.Length -ge 8 -and $Response.Bytes[0] -eq 0x89 -and $Response.Bytes[1] -eq 0x50 -and $Response.Bytes[2] -eq 0x4E -and $Response.Bytes[3] -eq 0x47
    Assert-True (-not ($jpeg -or $png)) "$Label leaked image bytes"
}

function Get-Items {
    param($Payload)

    $items = Get-PropertyValue $Payload 'items'
    if ($null -eq $items) { return @() }
    return @($items)
}

if (-not (Test-Path -LiteralPath $piwigoEnvPath) -or -not (Test-Path -LiteralPath $spikeEnvPath)) {
    throw 'missing_ignored_local_environment'
}
$settings = Read-DotEnv -Path $piwigoEnvPath
$httpPort = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
if ($httpPort -notmatch '^[0-9]{1,5}$') { throw 'invalid_class_archive_http_port' }

$baseUri = [Uri]("http://127.0.0.1:$httpPort/")
$compatUri = [Uri]'http://127.0.0.1:8091/'
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$piwigoCompose = @('-d', 'Ubuntu', '--cd', $projectRoot, '--', 'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml')
$spikeCompose = @('-d', 'Ubuntu', '--cd', $projectRoot, '--', 'docker', 'compose', '--env-file', 'infra/immich-spike/.env', '-f', 'infra/immich-spike/docker-compose.yml', '--profile', 'immich-web-compat')
$sessions = @{}
$fixturePassword = New-TransientSecret
$adminLease = $null
$failure = $null

try {
    Invoke-FixtureProvision -Compose $piwigoCompose -Password $fixturePassword
    $sessions['GUEST'] = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $sessions['CLASSMATE'] = Login-Role -Uri $wsUri -Username 'fixture-classmate' -Password $fixturePassword
    $sessions['TEACHER'] = Login-Role -Uri $wsUri -Username 'fixture-teacher' -Password $fixturePassword
    $sessions['FAMILY'] = Login-Role -Uri $wsUri -Username 'fixture-family' -Password $fixturePassword
    $sessions['ANONYMOUS'] = Login-Role -Uri $wsUri -Username 'fixture-anonymous' -Password $fixturePassword
    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $piwigoCompose -AdminUsername $adminUsername
    $sessions['SYSTEM_ADMIN'] = $adminLease.Session

    [void](Invoke-CompatCompose -Compose $spikeCompose -Arguments @('up', '-d', '--force-recreate', 'immich-web-compat'))
    Wait-CompatHealthy

    $lock = Get-Content -LiteralPath $upstreamLockPath -Raw | ConvertFrom-Json -ErrorAction Stop
    $inspection = (Invoke-UbuntuDocker -Arguments @('inspect', $compatContainer) | ConvertFrom-Json -ErrorAction Stop)[0]
    Assert-True ($inspection.State.Running -eq $true -and $inspection.State.Health.Status -eq 'healthy') 'compat_container_not_healthy'
    Assert-True ($inspection.Config.Image -eq [string]$lock.images.immich_server.pinned_reference -and $inspection.Image -eq [string]$lock.images.immich_server.digest) 'compat_image_digest_mismatch'
    Assert-True ($inspection.HostConfig.ReadonlyRootfs -eq $true) 'compat_rootfs_not_read_only'
    Assert-True ((@($inspection.HostConfig.CapDrop) -contains 'ALL') -and (@($inspection.HostConfig.SecurityOpt) -contains 'no-new-privileges:true')) 'compat_capability_hardening_missing'
    $mounts = @($inspection.Mounts)
    Assert-True ($mounts.Count -eq 3) 'compat_unexpected_mount_count'
    foreach ($mount in $mounts) {
        Assert-True ($mount.RW -eq $false -and [string]$mount.Destination -in @('/compat', '/web', '/data')) 'compat_mount_not_narrow_read_only'
    }
    $networkNames = @($inspection.NetworkSettings.Networks.PSObject.Properties | ForEach-Object { [string]$_.Name })
    Assert-True ($networkNames.Count -eq 1 -and $networkNames -contains 'class_archive_immich_gateway') 'compat_network_scope_invalid'
    Assert-True ($networkNames -notcontains 'class-archive-immich-spike_immich_internal' -and $networkNames -notcontains 'class_archive_piwigo_app') 'compat_joined_protected_network'
    $portBinding = Get-PropertyValue -Object $inspection.HostConfig.PortBindings -Name '3000/tcp'
    Assert-True ($null -eq $portBinding -or @($portBinding).Count -eq 0) 'compat_must_not_have_a_host_port'
    $piwigoInspection = (Invoke-UbuntuDocker -Arguments @('inspect', $piwigoContainer) | ConvertFrom-Json -ErrorAction Stop)[0]
    $webIngressBinding = @(Get-PropertyValue -Object $piwigoInspection.HostConfig.PortBindings -Name '8081/tcp')
    Assert-True ($webIngressBinding.Count -eq 1 -and [string]$webIngressBinding[0].HostIp -eq '127.0.0.1' -and [string]$webIngressBinding[0].HostPort -eq '8091') 'compat_nginx_loopback_ingress_invalid'
    $gatewayBinding = Get-PropertyValue -Object $piwigoInspection.HostConfig.PortBindings -Name '8088/tcp'
    Assert-True ($null -eq $gatewayBinding -or @($gatewayBinding).Count -eq 0) 'compat_internal_gateway_must_not_be_host_published'

    $health = Invoke-Http -Uri ([Uri]::new($compatUri, 'healthz'))
    Assert-PrivateResponse -Response $health -Status 200 -Label 'compat health'
    Assert-True ($health.Text -eq 'ok') 'compat_health_payload_invalid'

    $guestRoot = Invoke-Http -Uri $compatUri -Session $sessions['GUEST']
    Assert-PrivateResponse -Response $guestRoot -Status 303 -Label 'guest web root'
    Assert-True ($guestRoot.Location -eq "http://127.0.0.1:$httpPort/identification.php") 'guest_web_login_redirect_invalid'
    $guestIndex = Invoke-Http -Uri ([Uri]::new($compatUri, 'index.html')) -Session $sessions['GUEST']
    Assert-PrivateResponse -Response $guestIndex -Status 303 -Label 'guest explicit index'
    $guestMe = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Session $sessions['GUEST']
    Assert-Json -Response $guestMe -Status 401 -Label 'guest compatibility user' | Out-Null
    Assert-NoBackendLeak -Text $guestMe.Text -Label 'guest compatibility user'

    foreach ($role in @('CLASSMATE', 'TEACHER', 'FAMILY', 'ANONYMOUS', 'SYSTEM_ADMIN')) {
        $response = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Session $sessions[$role]
        $user = Assert-Json -Response $response -Status 200 -Label "$role compatibility user"
        Assert-NoBackendLeak -Text $response.Text -Label "$role compatibility user"
        Assert-True ([string]$user.id -match '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$') "$role compatibility user id was not opaque UUID"
        Assert-True ($user.isAdmin -eq $false) "$role compatibility user became Immich admin"
        Assert-True ([string]$user.email -eq 'class-archive@local.invalid') "$role compatibility user exposed an account email"
    }

    $classmateRoot = Invoke-Http -Uri $compatUri -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $classmateRoot -Status 200 -Label 'classmate web root'
    Assert-True ($classmateRoot.ContentType -like 'text/html*' -and $classmateRoot.Text.Contains('application-name" content="')) 'classmate_branding_document_invalid'
    Assert-True ($classmateRoot.Text.Contains('/custom.css') -and -not $classmateRoot.Text.Contains('class_archive_gateway')) 'classmate_document_boundary_invalid'
    $classmatePhotosRoute = Invoke-Http -Uri ([Uri]::new($compatUri, 'photos')) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $classmatePhotosRoute -Status 200 -Label 'classmate photos route'
    $classmateLegalNotice = Invoke-Http -Uri ([Uri]::new($compatUri, 'class-archive-about')) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $classmateLegalNotice -Status 200 -Label 'classmate legal notice'
    Assert-True ($classmateLegalNotice.ContentType -like 'text/html*' -and $classmateLegalNotice.Text.Contains('GNU AGPL-3.0-only') -and $classmateLegalNotice.Text.Contains('8aa95c67470a02a8ddedf03c2e52963af33065ff')) 'classmate_legal_notice_invalid'
    $guestArchiveTimeline = Invoke-Http -Uri ([Uri]::new($compatUri, 'class-archive-timeline')) -Session $sessions['GUEST']
    Assert-PrivateResponse -Response $guestArchiveTimeline -Status 303 -Label 'guest archive timeline'
    Assert-True ($guestArchiveTimeline.Location -eq "http://127.0.0.1:$httpPort/identification.php") 'guest_archive_timeline_login_redirect_invalid'
    $classmateArchiveTimelinePage = Invoke-Http -Uri ([Uri]::new($compatUri, 'class-archive-timeline')) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $classmateArchiveTimelinePage -Status 200 -Label 'classmate archive timeline page'
    Assert-True ($classmateArchiveTimelinePage.ContentType -like 'text/html*' -and $classmateArchiveTimelinePage.Text.Contains('档案时间轴') -and $classmateArchiveTimelinePage.Text.Contains('/api/class-archive/timeline')) 'classmate_archive_timeline_page_invalid'
    $authRoute = Invoke-Http -Uri ([Uri]::new($compatUri, 'auth/login')) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $authRoute -Status 303 -Label 'upstream login route'
    Assert-True ($authRoute.Location -eq "http://127.0.0.1:$httpPort/identification.php") 'upstream_login_route_not_redirected'

    $unsafeMethod = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Session $sessions['CLASSMATE'] -Method POST -JsonBody '{}'
    Assert-PrivateResponse -Response $unsafeMethod -Status 405 -Label 'compat unsafe method'
    $unsafePath = Invoke-Http -Uri ([Uri]::new($compatUri, '%2e%2e%2fweb-compat')) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $unsafePath -Status 400 -Label 'compat encoded traversal'
    $foreignOrigin = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Headers @{ Origin = 'https://invalid.example' }
    Assert-PrivateResponse -Response $foreignOrigin -Status 403 -Label 'compat foreign origin'
    $foreignFetch = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Headers @{ 'Sec-Fetch-Site' = 'cross-site' }
    Assert-PrivateResponse -Response $foreignFetch -Status 403 -Label 'compat foreign fetch'
    $gatewayClassmate = Invoke-Http -Uri ([Uri]::new($baseUri, 'api/photos')) -Session $sessions['CLASSMATE']
    $gatewayFamily = Invoke-Http -Uri ([Uri]::new($baseUri, 'api/photos')) -Session $sessions['FAMILY']
    $classmatePayload = Assert-Json -Response $gatewayClassmate -Status 200 -Label 'gateway classmate source'
    $familyPayload = Assert-Json -Response $gatewayFamily -Status 200 -Label 'gateway family source'
    $classmateItems = Get-Items $classmatePayload
    $familyItems = Get-Items $familyPayload
    $living = @($classmateItems | Where-Object { [string]$_.era -eq 'LIVING' } | Select-Object -First 1)
    $heritage = @($classmateItems | Where-Object { [string]$_.era -eq 'HERITAGE' } | Select-Object -First 1)
    Assert-True ($living.Count -eq 1 -and $heritage.Count -eq 1) 'canonical_fixture_era_candidates_missing'
    $livingId = [string]$living[0].id
    $heritageId = [string]$heritage[0].id
    Assert-True ([int]$classmatePayload.total -gt [int]$familyPayload.total) 'gateway fixture did not distinguish family visibility'
    $classmateArchivePhotoPage = Invoke-Http -Uri ([Uri]::new($compatUri, "class-archive-photo/$heritageId")) -Session $sessions['CLASSMATE']
    Assert-PrivateResponse -Response $classmateArchivePhotoPage -Status 200 -Label 'classmate archive photo viewer'
    Assert-True ($classmateArchivePhotoPage.ContentType -like 'text/html*' -and $classmateArchivePhotoPage.Text.Contains('/api/assets/') -and $classmateArchivePhotoPage.Text.Contains('/thumbnail?size=preview')) 'classmate_archive_photo_viewer_invalid'
    $familyLivingViewer = Invoke-Http -Uri ([Uri]::new($compatUri, "class-archive-photo/$livingId")) -Session $sessions['FAMILY']
    Assert-PrivateResponse -Response $familyLivingViewer -Status 404 -Label 'family living archive photo viewer'
    Assert-True ($familyLivingViewer.ContentType -notlike 'image/*' -and -not $familyLivingViewer.Text.Contains($livingId)) 'family_living_archive_viewer_leaked_hidden_uuid'

    $compatClassmateBuckets = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/timeline/buckets')) -Session $sessions['CLASSMATE']
    $compatFamilyBuckets = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/timeline/buckets')) -Session $sessions['FAMILY']
    $classmateBuckets = Assert-Json -Response $compatClassmateBuckets -Status 200 -Label 'classmate timeline buckets'
    $familyBuckets = Assert-Json -Response $compatFamilyBuckets -Status 200 -Label 'family timeline buckets'
    $classmateTotal = 0
    foreach ($bucket in @($classmateBuckets)) {
        if ($null -ne $bucket) { $classmateTotal += [int]$bucket.count }
    }
    $familyTotal = 0
    foreach ($bucket in @($familyBuckets)) {
        if ($null -ne $bucket) { $familyTotal += [int]$bucket.count }
    }
    # The generic Immich-shaped endpoint only receives confirmed day-level
    # archive dates. It must be a subset, never an upload-time fallback. The
    # complete precision-aware projection is asserted below.
    Assert-True ([int]$classmateTotal -le [int]$classmatePayload.total) 'classmate generic timeline invented unconfirmed dates'
    Assert-True ([int]$familyTotal -le [int]$familyPayload.total) 'family generic timeline invented unconfirmed dates'
    Assert-NoBackendLeak -Text $compatFamilyBuckets.Text -Label 'family timeline buckets'

    $classmateArchiveTimeline = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/class-archive/timeline')) -Session $sessions['CLASSMATE']
    $familyArchiveTimeline = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/class-archive/timeline')) -Session $sessions['FAMILY']
    $classmateArchivePayload = Assert-Json -Response $classmateArchiveTimeline -Status 200 -Label 'classmate archive timeline projection'
    $familyArchivePayload = Assert-Json -Response $familyArchiveTimeline -Status 200 -Label 'family archive timeline projection'
    Assert-True ([int]$classmateArchivePayload.total -eq [int]$classmatePayload.total) 'classmate archive timeline aggregation not policy filtered'
    Assert-True ([int]$familyArchivePayload.total -eq [int]$familyPayload.total) 'family archive timeline aggregation not policy filtered'
    Assert-True ([int]$familyArchivePayload.total -lt [int]$classmateArchivePayload.total) 'family archive timeline count leaked living media'
    $classmateArchiveItems = @($classmateArchivePayload.groups | ForEach-Object { @($_.items) })
    $familyArchiveItems = @($familyArchivePayload.groups | ForEach-Object { @($_.items) })
    Assert-True ($classmateArchiveItems.Count -eq [int]$classmateArchivePayload.total -and $familyArchiveItems.Count -eq [int]$familyArchivePayload.total) 'archive timeline item count inconsistent'
    Assert-True (-not (@($familyArchiveItems | Where-Object { [string]$_.id -eq $livingId }).Count -gt 0)) 'family archive timeline exposed living id'
    foreach ($item in $classmateArchiveItems) {
        Assert-True ($item.archive_date.label -is [string] -and $item.archive_date.precision -is [string] -and $item.archive_date.source -is [string]) 'archive timeline date projection missing chinese labels'
        Assert-True (-not ([string]$item.archive_date.precision -match '^(EXACT|DAY|MONTH|TERM|YEAR|EVENT_ONLY|UNKNOWN)$')) 'archive timeline exposed precision enum'
        Assert-True (-not ([string]$item.archive_date.source -match '^(ARCHIVE_CONFIRMED|EVENT_INFERENCE|EXIF_TRUSTED|UNKNOWN)$')) 'archive timeline exposed source enum'
    }
    Assert-NoBackendLeak -Text $familyArchiveTimeline.Text -Label 'family archive timeline projection'

    $familyAlbums = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/albums')) -Session $sessions['FAMILY']
    $familyAlbumPayload = Assert-Json -Response $familyAlbums -Status 200 -Label 'family compatibility albums'
    Assert-True (@($familyAlbumPayload).Count -le @($familyItems | ForEach-Object { @($_.albums) } | Select-Object -Unique).Count) 'family album aggregation leaked hidden album'
    Assert-NoBackendLeak -Text $familyAlbums.Text -Label 'family compatibility albums'

    foreach ($route in @('api/people', 'api/memories')) {
        $response = Invoke-Http -Uri ([Uri]::new($compatUri, $route)) -Session $sessions['FAMILY']
        $payload = Assert-Json -Response $response -Status 200 -Label "family $route"
        if ($route -eq 'api/people') {
            Assert-True ([int]$payload.total -eq 0 -and @($payload.people).Count -eq 0) 'people endpoint fabricated or leaked membership'
        }
        else {
            Assert-True (@($payload).Count -eq 0) 'memories endpoint fabricated or leaked membership'
        }
        Assert-NoBackendLeak -Text $response.Text -Label "family $route"
    }

    $livingTitle = [string]$living[0].title
    Assert-True (-not [string]::IsNullOrWhiteSpace($livingTitle)) 'living_fixture_title_missing'
    $searchPayload = '{"metadataSearchDto":{"originalFileName":' + (ConvertTo-Json -InputObject $livingTitle -Compress) + '}}'
    $classmateSearch = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/search/metadata')) -Session $sessions['CLASSMATE'] -Method POST -Headers @{ Origin = 'http://127.0.0.1:8091'; 'Sec-Fetch-Site' = 'same-origin' } -JsonBody $searchPayload
    $familySearch = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/search/metadata')) -Session $sessions['FAMILY'] -Method POST -Headers @{ Origin = 'http://127.0.0.1:8091'; 'Sec-Fetch-Site' = 'same-origin' } -JsonBody $searchPayload
    $classmateSearchPayload = Assert-Json -Response $classmateSearch -Status 200 -Label 'classmate read-only search'
    $familySearchPayload = Assert-Json -Response $familySearch -Status 200 -Label 'family read-only search'
    Assert-True ([int]$classmateSearchPayload.assets.total -ge 1) 'classmate living search found no matching photo'
    Assert-True ([int]$familySearchPayload.assets.total -eq 0 -and @($familySearchPayload.assets.items).Count -eq 0) 'family search count leaked living photo'
    Assert-NoBackendLeak -Text $familySearch.Text -Label 'family read-only search'

    $familyHeritageThumbnail = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$heritageId/thumbnail?size=thumbnail")) -Session $sessions['FAMILY'] -MaximumBytes 64 -PrefixOnly
    Assert-MediaAllow -Response $familyHeritageThumbnail -Status 200 -Label 'family heritage compatibility thumbnail'
    Assert-ImageMagic -Bytes $familyHeritageThumbnail.Bytes -Label 'family heritage compatibility thumbnail'
    $familyHeritageRange = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$heritageId/thumbnail?size=thumbnail")) -Session $sessions['FAMILY'] -Range32 -MaximumBytes 64 -PrefixOnly
    Assert-MediaAllow -Response $familyHeritageRange -Status 206 -Label 'family heritage compatibility range'
    Assert-True ($familyHeritageRange.ContentRange -match '^bytes 0-31/\d+$' -and $familyHeritageRange.Bytes.Length -eq 32) 'family compatibility range contract invalid'
    Assert-ImageMagic -Bytes $familyHeritageRange.Bytes -Label 'family heritage compatibility range'
    $familyHeritageHead = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$heritageId/thumbnail?size=thumbnail")) -Session $sessions['FAMILY'] -Method HEAD
    Assert-MediaAllow -Response $familyHeritageHead -Status 200 -Label 'family heritage compatibility head'
    Assert-True ($familyHeritageHead.Bytes.Length -eq 0) 'family compatibility head contained media bytes'
    $familyHeritageOriginal = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$heritageId/original")) -Session $sessions['FAMILY'] -MaximumBytes 64 -PrefixOnly
    Assert-MediaDeny -Response $familyHeritageOriginal -Status 403 -Label 'family heritage compatibility original'
    $familyLivingThumbnail = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$livingId/thumbnail?size=thumbnail")) -Session $sessions['FAMILY'] -MaximumBytes 64 -PrefixOnly
    Assert-MediaDeny -Response $familyLivingThumbnail -Status 404 -Label 'family living compatibility thumbnail'
    $classmateLivingPreview = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$livingId/thumbnail?size=preview")) -Session $sessions['CLASSMATE'] -MaximumBytes 64 -PrefixOnly
    Assert-MediaAllow -Response $classmateLivingPreview -Status 200 -Label 'classmate living compatibility preview'
    Assert-ImageMagic -Bytes $classmateLivingPreview.Bytes -Label 'classmate living compatibility preview'
    $invalidVariant = Invoke-Http -Uri ([Uri]::new($compatUri, "api/assets/$heritageId/thumbnail?size=original")) -Session $sessions['FAMILY']
    Assert-Json -Response $invalidVariant -Status 400 -Label 'compat invalid thumbnail variant' | Out-Null

    $adminUser = Invoke-Http -Uri ([Uri]::new($compatUri, 'api/users/me')) -Session $sessions['SYSTEM_ADMIN']
    $adminUserPayload = Assert-Json -Response $adminUser -Status 200 -Label 'system admin compatibility user'
    Assert-True ($adminUserPayload.isAdmin -eq $false) 'system admin compatibility projection gained Immich administration'
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
        catch { $cleanupFailures.Add('system-admin-lease') }
    }
    try {
        $cleanupPassword = New-TransientSecret
        try { Invoke-FixtureProvision -Compose $piwigoCompose -Password $cleanupPassword }
        finally { $cleanupPassword = $null }
    }
    catch { $cleanupFailures.Add('fixture-password-rotation') }
    if (-not $KeepRunning) {
        try { [void](Invoke-CompatCompose -Compose $spikeCompose -Arguments @('stop', 'immich-web-compat')) }
        catch { $cleanupFailures.Add('compat-stop') }
    }
    $fixturePassword = $null
    if ($cleanupFailures.Count -gt 0 -and $null -eq $failure) {
        $failure = [InvalidOperationException]::new('immich_web_compat_cleanup_failed_' + ($cleanupFailures -join ','))
    }
}

if ($null -ne $failure) { throw $failure }

Write-Output 'IMMICH_WEB_COMPAT_HTTP=PASS evidence=RUNTIME_TESTED'
Write-Output "HTTP_PROBES=$script:probes"
Write-Output "ASSERTIONS=$script:assertions"
Write-Output 'IMMICH_WEB_COMPAT_POLICY=CLASS_ARCHIVE_GATEWAY_MEDIAGUARD'
Write-Output 'IMMICH_WEB_AUTHORITY=CLASS_ARCHIVE_ONLY'
Write-Output 'IMMICH_WEB_BROWSER_E2E=SEPARATE_SUITE_REQUIRED'
