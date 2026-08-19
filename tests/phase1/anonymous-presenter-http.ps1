[CmdletBinding()]
param()

# Real Piwigo 16.4 + MariaDB privacy regression for AnonymousPresenter.
# The fixture uses only the synthetic Class Archive seed and always removes
# its comments/restores Core settings. Presenter readiness remains disabled on
# every failure and is enabled only after all ordinary HTML/WS surfaces pass.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:assertions = 0
$script:runId = ''
$script:fixtureReady = $false
$script:gateEnabled = $false
$script:compose = @()
$script:anonymousAliasPrefix = -join @([char]0x533F, [char]0x540D, ' ')
$script:anonymousAccountLabel = -join @([char]0x533F, [char]0x540D, [char]0x8D26, [char]0x53F7)

function Assert-True {
    param(
        [Parameter(Mandatory = $true)][bool]$Condition,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $script:assertions++
    if (-not $Condition) { throw "ANONYMOUS_PRESENTER_HTTP: $Message" }
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

function New-RunId {
    $bytes = New-Object byte[] 6
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-PropertyValue {
    param($Object, [Parameter(Mandatory = $true)][string]$Name)
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('setup', 'resolve', 'gate', 'assert-posted', 'cleanup', 'recover-orphan')]
        [string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId,
        [string]$Value = ''
    )
    $arguments = @($script:compose) + @(
        'exec', '-T', '--user', 'nginx', 'piwigo', 'php',
        '/workspace/tests/phase1/anonymous-presenter-fixture.php', $Action, $RunId
    )
    if ($Value.Length -gt 0) { $arguments += $Value }
    $output = @(& wsl.exe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw "Anonymous presenter fixture action $Action failed."
    }
    try { return (($output -join "`n") | ConvertFrom-Json) }
    catch { throw "Anonymous presenter fixture action $Action returned invalid JSON." }
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
        catch { throw 'Piwigo WS returned a non-generic error response.' }
    }
}

function Login-Role {
    param([Uri]$Uri, [string]$Username, [string]$Password, [string]$Role)
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS -Uri $Uri -Session $session -Body @{
        method='pwg.session.login'; username=$Username; password=$Password
    }
    Assert-True ($response.stat -eq 'ok' -and [bool]$response.result) "$Role login failed."
    return $session
}

function Invoke-Http {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session
    )
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = 'GET'
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-AnonymousPresenter-Regression/1.0'
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
            Location = [string]$response.Headers['Location']
            Text = $text
        }
    } finally { $response.Dispose() }
}

function ConvertTo-AbsoluteUri {
    param([Uri]$BaseUri, [string]$Reference)
    $decoded = [Net.WebUtility]::HtmlDecode($Reference)
    $candidate = [Uri]$decoded
    if ($candidate.IsAbsoluteUri) {
        return [Uri]::new($BaseUri, $candidate.PathAndQuery)
    }
    return [Uri]::new($BaseUri, $decoded)
}

function Assert-WsDenied {
    param($Response, [string]$Label, [int[]]$Codes = @(401, 403))
    $errorValue = Get-PropertyValue $Response 'err'
    $errorCode = if ($null -ne $errorValue) { [int]$errorValue } else { 0 }
    Assert-True ($Response.stat -eq 'fail' -and $errorCode -in $Codes) "$Label was not denied."
}

function Assert-NoRawLeak {
    param([string]$Text, [string[]]$ForbiddenValues, [string]$Label)
    foreach ($value in $ForbiddenValues) {
        if ([string]::IsNullOrEmpty($value)) { continue }
        Assert-True ($Text.IndexOf($value, [StringComparison]::OrdinalIgnoreCase) -lt 0) "$Label exposed a hidden principal value."
    }
}

function Assert-NoInternalIdentityLabels {
    param([string]$Text, [string]$Label)
    foreach ($field in @('identity_id','seat_id','account_id','principal_id','piwigo_user_id','author_id','user_id')) {
        $pattern = '(?i)(?:data-|["''])?' + [regex]::Escape($field) + '(?:["'']|\s|=|:)'
        Assert-True ($Text -notmatch $pattern) "$Label exposed an internal identity label."
    }
}

function Get-MarkerComment {
    param($Response, [string]$MarkerSuffix, [string]$Label)
    Assert-True ($Response.stat -eq 'ok') "$Label getInfo failed."
    $comments = @(Get-PropertyValue $Response.result 'comments')
    $rows = @($comments | Where-Object { [string](Get-PropertyValue $_ 'content') -eq $MarkerSuffix })
    Assert-True ($rows.Count -eq 1) "$Label marker comment was missing or ambiguous."
    return $rows[0]
}

function Assert-SafeCommentDto {
    param($Comment, [string]$Label)
    $author = [string](Get-PropertyValue $Comment 'author')
    $aliasPattern = '^' + [regex]::Escape($script:anonymousAliasPrefix) + '[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8,52}$'
    Assert-True ($author -match $aliasPattern) "$Label alias format was unsafe."
    foreach ($field in @('author_id','user_id','account_id','seat_id','identity_id','principal_id','profile_url','avatar','email','website_url','author_status')) {
        Assert-True ($null -eq (Get-PropertyValue $Comment $field)) "$Label exposed $field."
    }
    return $author
}

function Assert-ProfileDenied {
    param($Response, [string]$Label)
    Assert-True ($Response.Status -eq 403) "$Label profile was not HTTP 403."
    Assert-True ($Response.CacheControl -like '*no-store*') "$Label profile denial was cacheable."
    Assert-True ($Response.Text -eq 'Access denied.') "$Label profile denial was not generic."
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting $settings 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting $settings 'PIWIGO_ADMIN_USERNAME'
$baseUri = [Uri]("http://127.0.0.1:$port/")
$wsUri = [Uri]::new($baseUri, 'ws.php?format=json')
$fixturePassword = New-TransientSecret
$script:runId = New-RunId
$script:compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$adminLease = $null
try {
    $provisionArgs = @($script:compose) + @(
        'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
        'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
    )
    $provisionOutput = @(& wsl.exe @provisionArgs 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin $provisionOutput) {
        throw 'Synthetic access fixture provisioning failed.'
    }

    $state = Invoke-Fixture -Action setup -RunId $script:runId
    $script:fixtureReady = $true
    Assert-True ([bool]$state.attested) 'Presenter runtime did not attest readiness.'
    $gateOff = Invoke-Fixture -Action gate -RunId $script:runId -Value 'off'
    Assert-True ([bool]$gateOff.verified -and $gateOff.gate -eq 'off') 'Presenter gate did not fail closed before validation.'

    $classmate = Login-Role $wsUri 'fixture-classmate' $fixturePassword 'CLASSMATE'
    $anonymous = Login-Role $wsUri 'fixture-anonymous' $fixturePassword 'ANONYMOUS'
    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:compose -AdminUsername $adminUsername
    $systemAdmin = $adminLease.Session

    $photoIds = @($state.photo_ids | ForEach-Object { [int]$_ })
    Assert-True ($photoIds.Count -eq 2 -and $photoIds[0] -gt 0 -and $photoIds[1] -gt 0) 'Fixture did not provide two photo contexts.'
    $markerOne = [string]$state.marker + '_PHOTO_1'
    $markerTwo = [string]$state.marker + '_PHOTO_2'
    $forbiddenValues = @(
        [string]$state.anonymous.core_username,
        [string]$state.anonymous.roster_code,
        [string]$state.anonymous.real_name,
        "anonymous-leak-$($script:runId)@invalid.example",
        "identity-leak.invalid/$($script:runId)"
    )

    $infoOne = Invoke-WS $wsUri $classmate @{
        method='pwg.images.getInfo'; image_id=[string]$photoIds[0]; comments_page='0'; comments_per_page='50'
    }
    $commentOne = Get-MarkerComment $infoOne $markerOne 'PHOTO one'
    $aliasOne = Assert-SafeCommentDto $commentOne 'PHOTO one'
    Assert-NoRawLeak ($infoOne | ConvertTo-Json -Depth 20 -Compress) $forbiddenValues 'PHOTO one JSON'
    Assert-True ($null -eq (Get-PropertyValue $infoOne.result 'added_by')) 'PHOTO one JSON exposed a hidden uploader id.'

    $infoOneAgain = Invoke-WS $wsUri $classmate @{
        method='pwg.images.getInfo'; image_id=[string]$photoIds[0]; comments_page='0'; comments_per_page='50'
    }
    $aliasOneAgain = Assert-SafeCommentDto (Get-MarkerComment $infoOneAgain $markerOne 'PHOTO one repeat') 'PHOTO one repeat'
    Assert-True ($aliasOneAgain -ceq $aliasOne) 'The same Anonymous Seat changed alias inside one context.'

    $infoTwo = Invoke-WS $wsUri $classmate @{
        method='pwg.images.getInfo'; image_id=[string]$photoIds[1]; comments_page='0'; comments_per_page='50'
    }
    $commentTwo = Get-MarkerComment $infoTwo $markerTwo 'PHOTO two'
    $aliasTwo = Assert-SafeCommentDto $commentTwo 'PHOTO two'
    Assert-NoRawLeak ($infoTwo | ConvertTo-Json -Depth 20 -Compress) $forbiddenValues 'PHOTO two JSON'
    Assert-True ($aliasTwo -cne $aliasOne) 'One Anonymous Seat was correlatable across photo contexts.'

    # Piwigo's native technical comment console is retained for moderation,
    # but its Core author filter must not become an unaudited deanonymization
    # path for SYSTEM_ADMIN.
    $adminComments = Invoke-WS $wsUri $systemAdmin @{
        method='pwg.userComments.getList'; status='all'; page='0'; per_page='50'
    }
    $adminCommentOne = Get-MarkerComment $adminComments $markerOne 'Admin comment PHOTO one'
    $adminAliasOne = Assert-SafeCommentDto $adminCommentOne 'Admin comment PHOTO one'
    $adminCommentTwo = Get-MarkerComment $adminComments $markerTwo 'Admin comment PHOTO two'
    $adminAliasTwo = Assert-SafeCommentDto $adminCommentTwo 'Admin comment PHOTO two'
    Assert-True ($adminAliasOne -ceq $aliasOne -and $adminAliasTwo -ceq $aliasTwo) 'Admin moderation API did not use photo-context aliases.'
    Assert-NoRawLeak ($adminComments | ConvertTo-Json -Depth 20 -Compress) $forbiddenValues 'Admin moderation JSON'
    $adminAuthorFilters = @(Get-PropertyValue (Get-PropertyValue $adminComments.result 'filters') 'nb_authors')
    foreach ($filterRow in $adminAuthorFilters) {
        $filterAuthorId = [int](Get-PropertyValue $filterRow 'author_id')
        Assert-True ($filterAuthorId -notin @([int]$state.anonymous.piwigo_user_id, [int]$state.system_admin.piwigo_user_id)) 'Admin moderation filter exposed a hidden principal id.'
    }
    Assert-WsDenied (Invoke-WS $wsUri $systemAdmin @{
        method='pwg.userComments.getList'; status='all'; page='0'; per_page='50';
        author_id=[string]$state.anonymous.piwigo_user_id
    }) 'Admin hidden-author comment filter'

    # The governance list is intentionally not a bulk deanonymization view.
    # A SYSTEM_ADMIN may see aliases and contexts here, but the underlying
    # Classmate/Core identity must appear only after the explicit, audited
    # "查看真实身份" action.
    $adminAnonymousPage = Invoke-Http ([Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-anonymous')) $systemAdmin
    Assert-True ($adminAnonymousPage.Status -eq 200) 'Anonymous Governance page was unavailable to SYSTEM_ADMIN.'
    Assert-True ($adminAnonymousPage.Text -match 'name=["'']action["'']\s+value=["'']resolve_anonymous["'']') 'Anonymous Governance page omitted the explicit resolution action.'
    Assert-NoRawLeak $adminAnonymousPage.Text $forbiddenValues 'Anonymous Governance HTML'
    Assert-True ($adminAnonymousPage.Text -notmatch '(?i)(?:data-|["''])?identity_id(?:["'']|\s|=|:)') 'Anonymous Governance HTML exposed an underlying Identity identifier.'

    foreach ($index in 0..1) {
        $pictureUri = ConvertTo-AbsoluteUri $baseUri ([string]$state.picture_urls[$index])
        $picture = Invoke-Http $pictureUri $classmate
        Assert-True ($picture.Status -eq 200) "PHOTO $($index + 1) HTML was unavailable."
        $expectedAlias = if ($index -eq 0) { $aliasOne } else { $aliasTwo }
        Assert-True ($picture.Text.Contains($expectedAlias)) "PHOTO $($index + 1) HTML omitted its pseudonym."
        Assert-NoRawLeak $picture.Text $forbiddenValues "PHOTO $($index + 1) HTML"
        Assert-NoInternalIdentityLabels $picture.Text "PHOTO $($index + 1) HTML"
    }

    $commentsPage = Invoke-Http ([Uri]::new($baseUri, 'comments.php')) $classmate
    Assert-True ($commentsPage.Status -eq 200) 'Recent-comments HTML was unavailable.'
    Assert-True ($commentsPage.Text.Contains($aliasOne) -and $commentsPage.Text.Contains($aliasTwo)) 'Recent-comments HTML omitted context pseudonyms.'
    Assert-NoRawLeak $commentsPage.Text $forbiddenValues 'Recent-comments HTML'
    Assert-NoInternalIdentityLabels $commentsPage.Text 'Recent-comments HTML'

    $anonymousStatus = Invoke-WS $wsUri $anonymous @{ method='pwg.session.getStatus' }
    Assert-True ($anonymousStatus.stat -eq 'ok') 'Anonymous session status failed.'
    Assert-True ([string]$anonymousStatus.result.username -ceq $script:anonymousAccountLabel) 'Anonymous session exposed a stable Core username.'
    Assert-NoRawLeak ($anonymousStatus | ConvertTo-Json -Depth 10 -Compress) $forbiddenValues 'Anonymous session JSON'

    $anonymousHome = Invoke-Http $baseUri $anonymous
    Assert-True ($anonymousHome.Status -eq 200) 'Anonymous photo home was unavailable.'
    Assert-NoRawLeak $anonymousHome.Text $forbiddenValues 'Anonymous home HTML'
    Assert-NoInternalIdentityLabels $anonymousHome.Text 'Anonymous home HTML'
    Assert-ProfileDenied (Invoke-Http ([Uri]::new($baseUri, 'profile.php')) $anonymous) 'ANONYMOUS'
    Assert-ProfileDenied (Invoke-Http ([Uri]::new($baseUri, 'profile.php')) $systemAdmin) 'SYSTEM_ADMIN'

    $adminUsers = Invoke-WS $wsUri $systemAdmin @{ method='pwg.users.getList'; per_page='100'; page='0' }
    Assert-True ($adminUsers.stat -eq 'ok') 'Core admin user-list method was unavailable for the technical administrator.'
    Assert-WsDenied (Invoke-WS $wsUri $classmate @{ method='pwg.users.getList'; per_page='100'; page='0' }) 'Ordinary user-list API'
    Assert-WsDenied (Invoke-WS $wsUri $classmate @{
        method='pwg.images.filteredSearch.create'; added_by=[string]$state.anonymous.piwigo_user_id
    }) 'Anonymous uploader-id search'
    Assert-WsDenied (Invoke-WS $wsUri $classmate @{
        method='pwg.images.filteredSearch.create'; added_by=[string]$state.system_admin.piwigo_user_id
    }) 'SYSTEM_ADMIN uploader-id search'
    $searchStart = Invoke-Http ([Uri]::new($baseUri, 'search.php')) $classmate
    Assert-True ($searchStart.Status -in @(301, 302, 303) -and -not [string]::IsNullOrWhiteSpace($searchStart.Location)) 'Core search did not issue its expected local search route.'
    $searchPage = Invoke-Http (ConvertTo-AbsoluteUri $baseUri $searchStart.Location) $classmate
    Assert-True ($searchPage.Status -eq 200) 'Ordinary search page was unavailable.'
    Assert-NoRawLeak $searchPage.Text $forbiddenValues 'Ordinary search HTML'
    Assert-True ($searchPage.Text.IndexOf($adminUsername, [StringComparison]::OrdinalIgnoreCase) -lt 0) 'Ordinary search HTML exposed the SYSTEM_ADMIN account.'

    Assert-WsDenied (Invoke-WS $wsUri $anonymous @{
        method='pwg.images.addComment'; image_id=[string]$photoIds[0]; author='denied'; content='denied'; key='invalid'
    }) 'Anonymous comment while presenter gate is off' @(403)

    # Enable only after every presentation/discovery surface above passed.
    $gateOn = Invoke-Fixture -Action gate -RunId $script:runId -Value 'on'
    Assert-True ([bool]$gateOn.verified -and $gateOn.gate -eq 'on') 'Presenter readiness gate could not be enabled.'
    $script:gateEnabled = $true

    $anonymousInfo = Invoke-WS $wsUri $anonymous @{
        method='pwg.images.getInfo'; image_id=[string]$photoIds[0]; comments_page='0'; comments_per_page='50'
    }
    Assert-True ($anonymousInfo.stat -eq 'ok') 'Anonymous comment form metadata was unavailable.'
    $commentPost = Get-PropertyValue $anonymousInfo.result 'comment_post'
    $ephemeralKey = [string](Get-PropertyValue $commentPost 'key')
    Assert-True ($ephemeralKey.Length -ge 16) 'Anonymous comment form omitted its ephemeral key.'
    Assert-True ([string](Get-PropertyValue $commentPost 'author') -ceq $script:anonymousAccountLabel) 'Anonymous comment form exposed the Core username.'

    # Piwigo intentionally makes WS comment keys valid two seconds after
    # issuance as a bot trap. This is Core behavior, not an authorization wait.
    Start-Sleep -Milliseconds 2200
    $realContent = [string]$state.marker + '_REAL_HTTP'
    $posted = Invoke-WS $wsUri $anonymous @{
        method='pwg.images.addComment'; image_id=[string]$photoIds[0]; author='attempted-correlator';
        content=$realContent; key=$ephemeralKey
    }
    Assert-True ($posted.stat -eq 'ok') 'Real Anonymous HTTP comment was rejected after readiness.'
    $stored = Invoke-Fixture -Action assert-posted -RunId $script:runId
    Assert-True ([bool]$stored.posted -and [bool]$stored.stored_author_redacted) 'Anonymous HTTP comment persisted an identifying author field.'

    $afterPost = Invoke-WS $wsUri $classmate @{
        method='pwg.images.getInfo'; image_id=[string]$photoIds[0]; comments_page='0'; comments_per_page='50'
    }
    $realRow = Get-MarkerComment $afterPost $realContent 'Real HTTP comment'
    $realAlias = Assert-SafeCommentDto $realRow 'Real HTTP comment'
    Assert-True ($realAlias -ceq $aliasOne) 'A real comment did not reuse its photo-context pseudonym.'
    Assert-NoRawLeak ($afterPost | ConvertTo-Json -Depth 20 -Compress) $forbiddenValues 'Real comment JSON'
    $afterPicture = Invoke-Http (ConvertTo-AbsoluteUri $baseUri ([string]$state.picture_urls[0])) $classmate
    Assert-True ($afterPicture.Status -eq 200 -and $afterPicture.Text.Contains($aliasOne)) 'Real comment HTML did not render its context pseudonym.'
    Assert-NoRawLeak $afterPicture.Text $forbiddenValues 'Real comment HTML'

    $resolution = Invoke-Fixture -Action resolve -RunId $script:runId -Value $aliasOne
    Assert-True ([bool]$resolution.mapping_ok) 'SYSTEM_ADMIN could not resolve the context pseudonym.'
    Assert-True ([bool]$resolution.audit_ok) 'Anonymous resolution did not append a successful Audit event.'
    Assert-True ([bool]$resolution.audit_redacted) 'Anonymous resolution Audit contained a raw alias or identity credential.'
}
catch {
    if ($script:fixtureReady -and $script:runId -match '^[a-f0-9]{12}$') {
        try { [void](Invoke-Fixture -Action gate -RunId $script:runId -Value 'off') } catch {}
    }
    throw
}
finally {
    if ($script:fixtureReady -and $script:runId -match '^[a-f0-9]{12}$') {
        try { [void](Invoke-Fixture -Action cleanup -RunId $script:runId) }
        catch { [Console]::Error.WriteLine('ANONYMOUS_PRESENTER_HTTP: targeted fixture cleanup failed.') }
    } elseif ($script:runId -match '^[a-f0-9]{12}$') {
        try { [void](Invoke-Fixture -Action recover-orphan -RunId $script:runId) } catch {}
    }
    if ($null -ne $adminLease) {
        try { Remove-ClassArchiveSystemAdminSession -Lease $adminLease }
        catch { [Console]::Error.WriteLine('ANONYMOUS_PRESENTER_HTTP: exact SYSTEM_ADMIN session cleanup failed.') }
    }
    $fixturePassword = $null
}

Write-Output "CLASS_IDENTITY_ANONYMOUS_PRESENTER_HTTP=PASS assertions=$script:assertions"
Write-Output 'ANONYMOUS_PRESENTER_READY=TRUE'
Write-Output 'ANONYMOUS_COMMENT_GATE=ENABLED_AFTER_HTML_JSON_LEAK_SCAN'
Write-Output 'ANONYMOUS_RESOLUTION_AUDIT=PASS'
