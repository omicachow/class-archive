[CmdletBinding()]
param()

# Phase 1 production-facing ClassIdentity gate. Business authorization is
# proved only with real HTTP requests against Piwigo + MariaDB. The companion
# PHP fixture creates two deliberately unbound Core users, reads invariants and
# performs namespace-bounded cleanup; it is never used as authorization proof.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')
$script:failures = [Collections.Generic.List[string]]::new()
$script:pending = [Collections.Generic.List[string]]::new()
$script:probeCount = 0
$script:fixtureReady = $false
$script:baselineImageCount = $null
$script:runId = ''
$script:composeBase = @()

function Stop-Setup {
    param([Parameter(Mandatory = $true)][string]$Message)
    throw "CLASS_IDENTITY_HTTP_SETUP: $Message"
}

function Add-Failure {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $entry = "$Label :: $Message"
    if (-not $script:failures.Contains($entry)) { $script:failures.Add($entry) }
}

function Add-Pending {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $entry = "$Label :: $Message"
    if (-not $script:pending.Contains($entry)) { $script:pending.Add($entry) }
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { Stop-Setup 'Invalid .env.piwigo syntax.' }
        $values[$trimmed.Substring(0, $separator)] = $trimmed.Substring($separator + 1)
    }
    return $values
}

function Require-Setting {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][string]$Key
    )
    if (-not $Settings.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace([string]$Settings[$Key])) {
        Stop-Setup "Missing required ignored local setting: $Key."
    }
    return [string]$Settings[$Key]
}

function New-TransientSecret {
    param([ValidateRange(16, 128)][int]$Bytes = 32)
    $buffer = New-Object byte[] $Bytes
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($buffer).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function New-RunId {
    $buffer = New-Object byte[] 6
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($buffer) } finally { $generator.Dispose() }
    return (($buffer | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Invoke-Fixture {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('setup', 'state', 'expire-family', 'seed-provisioning-incident', 'seed-stale-provisioning', 'assert-no-secrets', 'cleanup')][string]$Action,
        [Parameter(Mandatory = $true)][string]$RunId,
        [hashtable]$Environment = @{}
    )
    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in $script:composeBase) { $arguments.Add($argument) }
    foreach ($argument in @('exec', '-T', '--user', 'nginx')) { $arguments.Add($argument) }
    foreach ($key in ($Environment.Keys | Sort-Object)) {
        $value = [string]$Environment[$key]
        if ($key -notmatch '^CI_TEST_[A-Z0-9_]+$' -or $value.Contains("`r") -or $value.Contains("`n")) {
            Stop-Setup 'Unsafe fixture environment input.'
        }
        $arguments.Add('-e')
        $arguments.Add("$key=$value")
    }
    foreach ($argument in @(
        'piwigo', 'php',
        '/workspace/tests/phase1/class-identity-fixture.php', $Action, $RunId
    )) { $arguments.Add($argument) }

    $output = @(& wsl.exe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        Stop-Setup "Fixture action '$Action' failed without exposing its output."
    }
    $json = ($output -join '').Trim()
    try { return $json | ConvertFrom-Json }
    catch { Stop-Setup "Fixture action '$Action' returned invalid JSON." }
}

function ConvertTo-FormBody {
    param([Parameter(Mandatory = $true)][hashtable]$Form)
    $pairs = foreach ($key in ($Form.Keys | Sort-Object)) {
        $value = $Form[$key]
        if (-not ($value -is [string]) -and -not ($value -is [ValueType])) {
            Stop-Setup 'HTTP form contains a non-scalar value.'
        }
        [Net.WebUtility]::UrlEncode([string]$key) + '=' + [Net.WebUtility]::UrlEncode([string]$value)
    }
    return $pairs -join '&'
}

function Invoke-Http {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [ValidateSet('GET', 'POST')][string]$Method = 'GET',
        [hashtable]$Form = @{},
        [AllowNull()][string]$Origin = $null,
        [ValidateRange(64, 4194304)][int]$MaxBodyBytes = 2097152,
        [string]$FetchSite = 'same-origin'
    )
    $script:probeCount++
    $request = [Net.HttpWebRequest]::Create($Uri)
    $request.Method = $Method
    $request.AllowAutoRedirect = $false
    $request.CookieContainer = $Session.Cookies
    $request.Timeout = 30000
    $request.ReadWriteTimeout = 30000
    $request.UserAgent = 'ClassArchive-ClassIdentity-Regression/1.0'
    $request.AutomaticDecompression = [Net.DecompressionMethods]::GZip -bor [Net.DecompressionMethods]::Deflate
    if ($null -ne $Origin) {
        $request.Headers['Origin'] = $Origin
        $request.Headers['Sec-Fetch-Site'] = $FetchSite
    }
    if ($Method -eq 'POST') {
        $bodyBytes = [Text.Encoding]::UTF8.GetBytes((ConvertTo-FormBody -Form $Form))
        $request.ContentType = 'application/x-www-form-urlencoded; charset=UTF-8'
        $request.ContentLength = $bodyBytes.Length
        $stream = $request.GetRequestStream()
        try { $stream.Write($bodyBytes, 0, $bodyBytes.Length) } finally { $stream.Dispose() }
    }

    $response = $null
    try { $response = [Net.HttpWebResponse]$request.GetResponse() }
    catch [Net.WebException] {
        if ($null -ne $_.Exception.Response) { $response = [Net.HttpWebResponse]$_.Exception.Response }
        else {
            return [pscustomobject]@{ Status = 0; Headers = @{}; Body = [byte[]]@(); Text = ''; ContentType = ''; Location = ''; TransportError = 'no HTTP response' }
        }
    }
    try {
        $headers = @{}
        foreach ($key in $response.Headers.AllKeys) { $headers[$key] = [string]$response.Headers[$key] }
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
        $bytes = $memory.ToArray()
        $memory.Dispose()
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            Headers = $headers
            Body = $bytes
            Text = [Text.Encoding]::UTF8.GetString($bytes)
            ContentType = [string]$response.ContentType
            Location = [string]$response.Headers['Location']
            TransportError = $null
        }
    } finally { $response.Dispose() }
}

function Assert-Status {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][int[]]$Expected,
        [Parameter(Mandatory = $true)][string]$Label
    )
    if ($null -ne $Response.TransportError) { Add-Failure $Label 'request returned no HTTP response'; return $false }
    if ($Response.Status -notin $Expected) {
        $message = "expected HTTP $($Expected -join '/') but received $($Response.Status)"
        $location = [string]$Response.Location
        if ($location -match '^[A-Za-z0-9_?&=./:%+\-]{1,240}$') {
            $message += " (Location: $location)"
        }
        Add-Failure $Label $message
        return $false
    }
    return $true
}

function Assert-NativeBusinessRouteRedirect {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$ExpectedTab,
        [Parameter(Mandatory = $true)][string]$Label
    )
    if (-not (Assert-Status -Response $Response -Expected @(303) -Label $Label)) { return }
    $location = [string]$Response.Location
    $expected = "plugin-ClassIdentity-$ExpectedTab"
    if ($location -notmatch [regex]::Escape($expected)) {
        Add-Failure $Label "legacy route did not redirect to the Class Archive $ExpectedTab console"
    }
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
    return $false
}

function Assert-MediaAllowed {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if (-not (Assert-Status -Response $Response -Expected @(200,206) -Label $Label)) { return }
    if ($Response.ContentType -notlike 'image/*' -or -not (Test-ImageMagic -Bytes $Response.Body)) {
        Add-Failure $Label 'allowed response did not contain recognized image bytes'
    }
}

function Assert-MediaDenied {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if ($Response.Status -in @(200,206) -or $Response.ContentType -like 'image/*' -or (Test-ImageMagic -Bytes $Response.Body)) {
        Add-Failure $Label 'denied request returned media bytes'
    }
}

function Assert-ProtectedPageDenied {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if ($Response.Status -eq 403) { return }
    if ($Response.Status -eq 302 -and [string]$Response.Location -match '^identification\.php(?:\?|$)') { return }
    [void](Assert-Status -Response $Response -Expected @(403) -Label $Label)
}

function ConvertTo-AbsoluteUri {
    param([Parameter(Mandatory = $true)][Uri]$BaseUri, [Parameter(Mandatory = $true)][string]$Reference)
    return [Uri]::new($BaseUri, [Net.WebUtility]::HtmlDecode($Reference))
}

function Get-CsrfToken {
    param([Parameter(Mandatory = $true)]$Response, [Parameter(Mandatory = $true)][string]$Label)
    if (-not (Assert-Status -Response $Response -Expected @(200) -Label $Label)) { return $null }
    $match = [regex]::Match($Response.Text, 'name=["'']pwg_token["''][^>]*value=["'']([^"'']+)["'']', 'IgnoreCase')
    if (-not $match.Success) {
        Add-Pending $Label 'required Piwigo CSRF form surface was not rendered'
        return $null
    }
    return [Net.WebUtility]::HtmlDecode($match.Groups[1].Value)
}

function Get-OneTimeCode {
    param(
        [Parameter(Mandatory = $true)]$Response,
        [Parameter(Mandatory = $true)][string]$CssClass,
        [Parameter(Mandatory = $true)][string]$Label
    )
    if (-not (Assert-Status -Response $Response -Expected @(200) -Label $Label)) { return $null }
    $pattern = '<code[^>]*class=["''][^"'']*' + [regex]::Escape($CssClass) + '[^"'']*["''][^>]*>([^<]+)</code>'
    $match = [regex]::Match($Response.Text, $pattern, 'IgnoreCase')
    if (-not $match.Success) {
        Add-Pending $Label 'one-time secret response surface is not implemented'
        return $null
    }
    $code = [Net.WebUtility]::HtmlDecode($match.Groups[1].Value).Trim()
    if ($code -notmatch '^[A-Za-z0-9_-]{20,32}\.[A-Za-z0-9_-]{40,64}$') {
        Add-Failure $Label 'one-time code has an unexpected shape'
        return $null
    }
    return $code
}

function New-AuthenticatedSession {
    param(
        [Parameter(Mandatory = $true)][Uri]$WebServiceUri,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$Label,
        [switch]$ExpectDenied
    )
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-Http -Uri $WebServiceUri -Session $session -Method POST -Form @{
        method = 'pwg.session.login'; username = $Username; password = $Password
    }
    $accepted = $false
    if ($response.Status -eq 200) {
        try {
            $payload = $response.Text | ConvertFrom-Json
            $accepted = $payload.stat -eq 'ok' -and [bool]$payload.result
        } catch { $accepted = $false }
    }
    if ($ExpectDenied) {
        if ($accepted) { Add-Failure $Label 'unbound/frozen account login was accepted' }
        return $session
    }
    if (-not $accepted) { Add-Failure $Label 'expected account login was denied'; return $null }
    return $session
}

function Invoke-ClaimAttempt {
    param(
        [Parameter(Mandatory = $true)][Uri]$ClaimUri,
        [Parameter(Mandatory = $true)][Uri]$Origin,
        [Parameter(Mandatory = $true)][string]$RosterCode,
        [Parameter(Mandatory = $true)][string]$ClaimCode,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][bool]$ExpectSuccess,
        [Parameter(Mandatory = $true)][string]$Label
    )
    $guest = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $get = Invoke-Http -Uri $ClaimUri -Session $guest
    $token = Get-CsrfToken -Response $get -Label "$Label/form"
    if ($null -eq $token) { return }
    $post = Invoke-Http -Uri $ClaimUri -Session $guest -Method POST -Origin $Origin.GetLeftPart([UriPartial]::Authority) -Form @{
        pwg_token = $token; action = 'claim'; roster_code = $RosterCode; claim_code = $ClaimCode
        username = $Username; email = "$Username@class-archive.invalid"
        password = $Password; password_confirmation = $Password
    }
    if ($ExpectSuccess) {
        if (-not (Assert-Status -Response $post -Expected @(200) -Label $Label)) { return }
        if ($post.Text -match 'name=["'']claim_code["'']') {
            Add-Failure $Label 'valid one-time Claim did not create an account'
        }
    } else {
        if ($post.Status -notin @(200,400,403)) { Add-Failure $Label "invalid/reused Claim returned HTTP $($post.Status)" }
        if ($post.Status -eq 200 -and $post.Text -notmatch 'name=["'']claim_code["'']') { Add-Failure $Label 'invalid/reused Claim created an account' }
    }
}

function Invoke-FamilyAcceptance {
    param(
        [Parameter(Mandatory = $true)][Uri]$FamilyUri,
        [Parameter(Mandatory = $true)][Uri]$Origin,
        [Parameter(Mandatory = $true)][string]$InvitationCode,
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password,
        [Parameter(Mandatory = $true)][string]$RealName,
        [bool]$ExpectSuccess = $true,
        [string]$Label = 'family-accept'
    )
    $guest = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $get = Invoke-Http -Uri $FamilyUri -Session $guest
    $token = Get-CsrfToken -Response $get -Label "$Label/form"
    if ($null -eq $token) { return }
    $post = Invoke-Http -Uri $FamilyUri -Session $guest -Method POST -Origin $Origin.GetLeftPart([UriPartial]::Authority) -Form @{
        pwg_token = $token; action = 'accept_family'; invitation_code = $InvitationCode
        username = $Username; email = "$Username@class-archive.invalid"; real_name = $RealName
        relationship = 'GUARDIAN'; password = $Password; password_confirmation = $Password
    }
    if ($ExpectSuccess) {
        if (-not (Assert-Status -Response $post -Expected @(200) -Label $Label)) { return }
        if ($post.Text -match 'name=["'']invitation_code["'']') { Add-Failure $Label 'valid Family Invitation did not create an account' }
    } else {
        if ($post.Status -notin @(200,400,403)) { Add-Failure $Label "invalid/revoked Family Invitation returned HTTP $($post.Status)" }
        if ($post.Status -eq 200 -and $post.Text -notmatch 'name=["'']invitation_code["'']') { Add-Failure $Label 'invalid/revoked Family Invitation created an account' }
    }
}

if (-not (Test-Path -LiteralPath $envPath)) { Stop-Setup 'Missing ignored .env.piwigo.' }
$settings = Read-DotEnv -Path $envPath
$port = Require-Setting -Settings $settings -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Require-Setting -Settings $settings -Key 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^[0-9]{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) { Stop-Setup 'Invalid localhost HTTP port.' }
$baseUri = [Uri]("http://127.0.0.1:$port/")
$origin = [Uri]$baseUri.GetLeftPart([UriPartial]::Authority)
$webServiceUri = [Uri]::new($baseUri, 'ws.php?format=json')
$adminDashboardUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-dashboard')
$adminIdentitiesUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-identities')
$adminTeachersUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-teachers')
$adminInvitationsUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-invitations')
$adminSystemUri = [Uri]::new($baseUri, 'admin.php?page=plugin-ClassIdentity-system')
$adminDirectUri = [Uri]::new($baseUri, 'admin.php?page=plugin&section=ClassIdentity/admin.php')
$coreAdminProfileUri = [Uri]::new($baseUri, 'admin.php?page=profile')
$coreAdminUsersUri = [Uri]::new($baseUri, 'admin.php?page=user_list')
$claimUri = [Uri]::new($baseUri, 'index.php?/class-identity/claim')
$familyUri = [Uri]::new($baseUri, 'index.php?/class-identity/family-invite')
$myUri = [Uri]::new($baseUri, 'index.php?/class-identity/my')
$script:composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$script:runId = New-RunId
$runUpper = $script:runId.ToUpperInvariant()
$classmateRoster = "CIT-C-$runUpper"
$teacherRoster = "CIT-T-$runUpper"
$csrfRosterOne = "CIT-X-$runUpper-A"
$csrfRosterTwo = "CIT-X-$runUpper-B"
$csrfRosterThree = "CIT-X-$runUpper-C"
$csrfRosterFour = "CIT-X-$runUpper-D"
$userPrefix = "cit_$($script:runId)_"
$unboundPassword = New-TransientSecret
$classmatePassword = New-TransientSecret
$teacherPassword = New-TransientSecret
$familyPassword = New-TransientSecret
$incidentPassword = New-TransientSecret
$invalidOldPassword = New-TransientSecret
$invalidReusePassword = New-TransientSecret
$reasonTokenCanary = "$(New-TransientSecret -Bytes 18).$(New-TransientSecret -Bytes 32)"
$reasonPasswordCanary = New-TransientSecret
$secrets = [Collections.Generic.List[string]]::new()
foreach ($secret in @($unboundPassword, $classmatePassword, $teacherPassword, $familyPassword, $incidentPassword, $invalidOldPassword, $invalidReusePassword, $reasonTokenCanary, $reasonPasswordCanary)) {
    if (-not $secrets.Contains($secret)) { $secrets.Add($secret) }
}
$logsSince = [DateTime]::UtcNow.AddSeconds(-2).ToString('o')

# The namespace is not a credential. Printing it makes an interrupted run
# recoverable without exposing any Claim, Invitation or password material.
Write-Output "CLASS_IDENTITY_HTTP_RUN=$($script:runId)"

$adminLease = $null
$coreProfileAdminLease = $null
$coreUsersAdminLease = $null
try {
    $setup = Invoke-Fixture -Action setup -RunId $script:runId -Environment @{ CI_TEST_UNBOUND_PASSWORD = $unboundPassword }
    $script:fixtureReady = $true
    $script:baselineImageCount = [int]$setup.baseline_image_count
    if ($script:baselineImageCount -lt 1) { Stop-Setup 'Synthetic image baseline is empty.' }
    $livingImageId = [int]$setup.living_image_id
    $livingOriginalPath = [string]$setup.living_original_path
    if ($livingImageId -lt 1 -or $livingOriginalPath -notmatch '^upload/[A-Za-z0-9_./-]+$') {
        Stop-Setup 'Fixture returned an unsafe LIVING media reference.'
    }

    # Unbound normal and admin Core users must fail before a ClassIdentity
    # principal can be minted. A high Core status alone is never sufficient.
    [void](New-AuthenticatedSession -WebServiceUri $webServiceUri -Username ([string]$setup.unbound_normal.username) -Password $unboundPassword -Label 'login/unbound-normal' -ExpectDenied)
    [void](New-AuthenticatedSession -WebServiceUri $webServiceUri -Username ([string]$setup.unbound_admin.username) -Password $unboundPassword -Label 'login/unbound-admin' -ExpectDenied)

    $adminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:composeBase -AdminUsername $adminUsername
    $adminSession = $adminLease.Session
    if ($null -eq $adminSession) { Stop-Setup 'SYSTEM_ADMIN session unavailable.' }
    $guestSession = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    Assert-Status (Invoke-Http -Uri $adminDashboardUri -Session $guestSession) @(403) 'admin/guest-denied' | Out-Null
    Assert-Status (Invoke-Http -Uri $adminDashboardUri -Session $adminSession) @(200) 'admin/system-admin-allowed' | Out-Null
    # Legacy native identity pages are not allowed to reach Core mutation
    # controllers. A SYSTEM_ADMIN GET is redirected to the audited business
    # console; isolated sessions keep these compatibility probes independent
    # from the business-console session used by later mutations.
    $coreProfileAdminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:composeBase -AdminUsername $adminUsername
    $coreUsersAdminLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri -ComposeBase $script:composeBase -AdminUsername $adminUsername
    $coreProfileAdminSession = $coreProfileAdminLease.Session
    $coreUsersAdminSession = $coreUsersAdminLease.Session
    if ($null -eq $coreProfileAdminSession -or $null -eq $coreUsersAdminSession) {
        Stop-Setup 'SYSTEM_ADMIN native-route probe sessions unavailable.'
    }
    Assert-NativeBusinessRouteRedirect (Invoke-Http -Uri $coreAdminProfileUri -Session $coreProfileAdminSession) 'identities' 'admin/core-profile-business-route-redirected'
    Assert-NativeBusinessRouteRedirect (Invoke-Http -Uri $coreAdminUsersUri -Session $coreUsersAdminSession) 'identities' 'admin/core-user-list-business-route-redirected'

    # Admin and public credential-bearing mutations both reject missing CSRF
    # and cross-origin submissions. Mutation targets are later asserted absent.
    $adminPage = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession
    $adminToken = Get-CsrfToken -Response $adminPage -Label 'admin/identities-form'
    if ($null -eq $adminToken) { Stop-Setup 'Admin Identity form is unavailable.' }
    $missingAdminCsrf = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        action = 'create_classmate'; roster_code = $csrfRosterOne; real_name = 'CITEST CSRF Missing'; reason = 'Phase1 CSRF negative probe'
    }
    Assert-Status $missingAdminCsrf @(403) 'csrf/admin-missing-token' | Out-Null
    $evilAdminOrigin = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin 'https://attacker.invalid' -FetchSite 'cross-site' -Form @{
        pwg_token = $adminToken; action = 'create_classmate'; roster_code = $csrfRosterTwo; real_name = 'CITEST CSRF Origin'; reason = 'Phase1 Origin negative probe'
    }
    Assert-Status $evilAdminOrigin @(403) 'csrf/admin-cross-origin' | Out-Null

    # Operator reasons are untrusted persistence input. Credential-shaped
    # values must fail at the HTTP boundary, never be reflected, and remain
    # absent from Identity/Audit/DB/session/container logs (scanned below).
    $sensitiveTokenReason = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token = $adminToken; action = 'create_classmate'; roster_code = $csrfRosterThree; real_name = 'CITEST Sensitive Token'; reason = "Reissue $reasonTokenCanary"
    }
    Assert-Status $sensitiveTokenReason @(400) 'audit-reason/raw-token-denied' | Out-Null
    if ($sensitiveTokenReason.Text.IndexOf($reasonTokenCanary, [StringComparison]::Ordinal) -ge 0) {
        Add-Failure 'audit-reason/raw-token-reflection' 'rejected credential was reflected in HTTP output'
    }
    $sensitivePasswordReason = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token = $adminToken; action = 'create_classmate'; roster_code = $csrfRosterFour; real_name = 'CITEST Sensitive Password'; reason = "password=$reasonPasswordCanary"
    }
    Assert-Status $sensitivePasswordReason @(400) 'audit-reason/raw-password-denied' | Out-Null
    if ($sensitivePasswordReason.Text.IndexOf($reasonPasswordCanary, [StringComparison]::Ordinal) -ge 0) {
        Add-Failure 'audit-reason/raw-password-reflection' 'rejected credential was reflected in HTTP output'
    }

    $publicGuest = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $claimForm = Invoke-Http -Uri $claimUri -Session $publicGuest
    $publicToken = Get-CsrfToken -Response $claimForm -Label 'csrf/public-form'
    if ($null -ne $publicToken) {
        $publicMissing = Invoke-Http -Uri $claimUri -Session $publicGuest -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
            action='claim'; roster_code=$classmateRoster; claim_code='invalid.invalid'; username="${userPrefix}csrf_missing"; email="${userPrefix}csrf_missing@class-archive.invalid"; password=$invalidOldPassword; password_confirmation=$invalidOldPassword
        }
        Assert-Status $publicMissing @(403) 'csrf/public-missing-token' | Out-Null
        $publicCross = Invoke-Http -Uri $claimUri -Session $publicGuest -Method POST -Origin 'https://attacker.invalid' -FetchSite 'cross-site' -Form @{
            pwg_token=$publicToken; action='claim'; roster_code=$classmateRoster; claim_code='invalid.invalid'; username="${userPrefix}csrf_origin"; email="${userPrefix}csrf_origin@class-archive.invalid"; password=$invalidOldPassword; password_confirmation=$invalidOldPassword
        }
        Assert-Status $publicCross @(403) 'csrf/public-cross-origin' | Out-Null
    }

    # Create Classmate Identity through the business console.
    $createClassmate = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$adminToken; action='create_classmate'; roster_code=$classmateRoster; real_name="Synthetic Classmate $runUpper"; reason='Roster verification completed'
    }
    if (-not (Assert-Status $createClassmate @(303) 'identity/create-classmate')) { Stop-Setup 'Classmate Identity could not be created.' }
    $classmateLocation = ConvertTo-AbsoluteUri -BaseUri $baseUri -Reference $createClassmate.Location
    $identityMatch = [regex]::Match($classmateLocation.Query, '(?:^|[?&])identity_id=([0-9]+)(?:&|$)')
    if (-not $identityMatch.Success) { Stop-Setup 'Classmate redirect omitted identity_id.' }
    $classmateIdentityId = [int]$identityMatch.Groups[1].Value
    $classmateDetail = Invoke-Http -Uri $classmateLocation -Session $adminSession
    $classmateAdminToken = Get-CsrfToken -Response $classmateDetail -Label 'identity/classmate-detail'
    if ($null -eq $classmateAdminToken) { Stop-Setup 'Classmate detail form unavailable.' }

    # Reissue immediately, proving generation 1 is invalid before generation 2
    # is consumed. Both raw codes are returned only in their POST responses.
    $issueOne = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$classmateAdminToken; action='reissue_claim'; identity_id=$classmateIdentityId; reason='Phase1 initial Claim issue'
    }
    if ($issueOne.Status -eq 303 -and -not [string]::IsNullOrWhiteSpace($issueOne.Location)) {
        foreach ($headerName in $issueOne.Headers.Keys) {
            if ([string]$headerName -match '(?i)^X-Class-Identity-Error-') {
                $headerValue = [string]$issueOne.Headers[$headerName]
                if ($headerValue -match '^[A-Za-z0-9_.:\- ]{1,240}$') {
                    Add-Failure 'claim/issue-generation-1' "bounded response diagnostic $headerName=$headerValue"
                }
            }
        }
        $issueErrorPage = Invoke-Http -Uri (ConvertTo-AbsoluteUri -BaseUri $baseUri -Reference $issueOne.Location) -Session $adminSession
        $issueError = [regex]::Match($issueErrorPage.Text, '<div[^>]*class=["''][^"'']*ca-admin__alert--error[^"'']*["''][^>]*>([\s\S]*?)</div>', 'IgnoreCase')
        if ($issueError.Success) {
            $boundedMessage = [regex]::Replace([Net.WebUtility]::HtmlDecode($issueError.Groups[1].Value), '<[^>]+>', ' ')
            $boundedMessage = [regex]::Replace($boundedMessage, '\s+', ' ').Trim()
            if ($boundedMessage.Length -gt 240) { $boundedMessage = $boundedMessage.Substring(0, 240) }
            Add-Failure 'claim/issue-generation-1' "admin mutation redirected with: $boundedMessage"
        }
    }
    $claimOne = Get-OneTimeCode -Response $issueOne -CssClass 'ca-admin__code' -Label 'claim/issue-generation-1'
    if ($null -eq $claimOne) { Stop-Setup 'Initial Classmate Claim surface unavailable.' }
    $secrets.Add($claimOne)
    $classmateDetail = Invoke-Http -Uri $classmateLocation -Session $adminSession
    $classmateAdminToken = Get-CsrfToken -Response $classmateDetail -Label 'claim/reissue-form'
    $issueTwo = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$classmateAdminToken; action='reissue_claim'; identity_id=$classmateIdentityId; reason='Phase1 Claim reissue invalidates old token'
    }
    $claimTwo = Get-OneTimeCode -Response $issueTwo -CssClass 'ca-admin__code' -Label 'claim/issue-generation-2'
    if ($null -eq $claimTwo) { Stop-Setup 'Reissued Classmate Claim surface unavailable.' }
    $secrets.Add($claimTwo)
    Invoke-ClaimAttempt -ClaimUri $claimUri -Origin $origin -RosterCode $classmateRoster -ClaimCode $claimOne -Username "${userPrefix}old_claim" -Password $invalidOldPassword -ExpectSuccess $false -Label 'claim/reissued-old-token-denied'
    $classmateUsername = "${userPrefix}classmate"
    Invoke-ClaimAttempt -ClaimUri $claimUri -Origin $origin -RosterCode $classmateRoster -ClaimCode $claimTwo -Username $classmateUsername -Password $classmatePassword -ExpectSuccess $true -Label 'claim/classmate-one-time-success'
    Invoke-ClaimAttempt -ClaimUri $claimUri -Origin $origin -RosterCode $classmateRoster -ClaimCode $claimTwo -Username "${userPrefix}reuse_claim" -Password $invalidReusePassword -ExpectSuccess $false -Label 'claim/classmate-reuse-denied'
    $classmateSession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $classmateUsername -Password $classmatePassword -Label 'login/classmate'
    if ($null -eq $classmateSession) { Stop-Setup 'Classmate session unavailable.' }

    # Teacher Identity has one formal Seat and one account only.
    $teacherPage = Invoke-Http -Uri $adminTeachersUri -Session $adminSession
    $teacherToken = Get-CsrfToken -Response $teacherPage -Label 'teacher/create-form'
    if ($null -eq $teacherToken) { Stop-Setup 'Teacher form unavailable.' }
    $createTeacher = Invoke-Http -Uri $adminTeachersUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$teacherToken; action='create_teacher'; roster_code=$teacherRoster; real_name="Synthetic Teacher $runUpper"; reason='Phase1 real HTTP acceptance fixture'
    }
    if (-not (Assert-Status $createTeacher @(303) 'identity/create-teacher')) { Stop-Setup 'Teacher Identity could not be created.' }
    $teacherLocation = ConvertTo-AbsoluteUri -BaseUri $baseUri -Reference $createTeacher.Location
    $teacherMatch = [regex]::Match($teacherLocation.Query, '(?:^|[?&])identity_id=([0-9]+)(?:&|$)')
    if (-not $teacherMatch.Success) { Stop-Setup 'Teacher redirect omitted identity_id.' }
    $teacherIdentityId = [int]$teacherMatch.Groups[1].Value
    $teacherDetail = Invoke-Http -Uri $teacherLocation -Session $adminSession
    $teacherAdminToken = Get-CsrfToken -Response $teacherDetail -Label 'teacher/detail'
    if ($null -eq $teacherAdminToken) { Stop-Setup 'Teacher detail form unavailable.' }
    $teacherIssue = Invoke-Http -Uri $adminTeachersUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$teacherAdminToken; action='issue_claim'; identity_id=$teacherIdentityId; reason='Phase1 Teacher Claim issue'
    }
    $teacherClaim = Get-OneTimeCode -Response $teacherIssue -CssClass 'ca-admin__code' -Label 'claim/teacher-issue'
    if ($null -eq $teacherClaim) { Stop-Setup 'Teacher Claim surface unavailable.' }
    $secrets.Add($teacherClaim)
    $teacherUsername = "${userPrefix}teacher"
    Invoke-ClaimAttempt -ClaimUri $claimUri -Origin $origin -RosterCode $teacherRoster -ClaimCode $teacherClaim -Username $teacherUsername -Password $teacherPassword -ExpectSuccess $true -Label 'claim/teacher-one-time-success'
    Invoke-ClaimAttempt -ClaimUri $claimUri -Origin $origin -RosterCode $teacherRoster -ClaimCode $teacherClaim -Username "${userPrefix}teacher_reuse" -Password $invalidReusePassword -ExpectSuccess $false -Label 'claim/teacher-reuse-denied'
    $teacherSession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $teacherUsername -Password $teacherPassword -Label 'login/teacher'
    if ($null -eq $teacherSession) { Stop-Setup 'Teacher session unavailable.' }

    # Expiry is a durable lifecycle transition, not a failed transaction: the
    # old token becomes EXPIRED and its Seat returns to AVAILABLE. SYSTEM_ADMIN
    # then reissues the same Seat and receives the new validator only once.
    $myClassmate = Invoke-Http -Uri $myUri -Session $classmateSession
    $myToken = Get-CsrfToken -Response $myClassmate -Label 'family/my-form'
    if ($null -eq $myToken) { Stop-Setup 'Classmate My surface unavailable.' }
    $familyIssue = Invoke-Http -Uri $myUri -Session $classmateSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$myToken; action='issue_family_invitation'
    }
    $familyInvitation = Get-OneTimeCode -Response $familyIssue -CssClass 'ca-public__secret' -Label 'family/invitation-issue'
    if ($null -eq $familyInvitation) { Stop-Setup 'Family Invitation surface unavailable.' }
    $secrets.Add($familyInvitation)
    $expiredFamily = Invoke-Fixture -Action expire-family -RunId $script:runId
    Invoke-FamilyAcceptance -FamilyUri $familyUri -Origin $origin -InvitationCode $familyInvitation -Username "${userPrefix}expired_family" -Password $invalidOldPassword -RealName "Expired Family $runUpper" -ExpectSuccess $false -Label 'family/expired-token-denied'
    $expiredState = Invoke-Fixture -Action state -RunId $script:runId
    $expiredToken = @($expiredState.tokens | Where-Object { [int]$_.id -eq [int]$expiredFamily.token_id })
    $expiredSeat = @($expiredState.classmate.seats | Where-Object { [int]$_.id -eq [int]$expiredFamily.seat_id })
    if ($expiredToken.Count -ne 1 -or $expiredToken[0].state -ne 'EXPIRED' -or $expiredSeat.Count -ne 1 -or $expiredSeat[0].state -ne 'AVAILABLE') {
        Add-Failure 'family/expiry-releases-seat' 'expired Invitation did not atomically become EXPIRED and release its Seat'
    }
    $expiryAudit = @($expiredState.audit | Where-Object { $_.action -eq 'FAMILY_INVITATION_EXPIRE' -and $_.result -eq 'SUCCESS' })
    if ($expiryAudit.Count -ne 1) { Add-Failure 'audit/family-expiry' 'Family Invitation expiry did not create an Audit event' }

    $invitationsPage = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession
    $invitationAdminToken = Get-CsrfToken -Response $invitationsPage -Label 'family/admin-reissue-form'
    if ($null -eq $invitationAdminToken) { Stop-Setup 'Admin Invitations form unavailable.' }
    $familyReissue = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$invitationAdminToken; action='reissue_family_invitation'; seat_id=[int]$expiredFamily.seat_id; reason='Phase1 expired Family Invitation reissue'
    }
    $familyInvitationReissued = Get-OneTimeCode -Response $familyReissue -CssClass 'ca-public__secret' -Label 'family/admin-reissue'
    if ($null -eq $familyInvitationReissued) { Stop-Setup 'Admin Family Invitation reissue surface unavailable.' }
    $secrets.Add($familyInvitationReissued)
    $familyUsername = "${userPrefix}family"
    Invoke-FamilyAcceptance -FamilyUri $familyUri -Origin $origin -InvitationCode $familyInvitationReissued -Username $familyUsername -Password $familyPassword -RealName "Synthetic Family $runUpper" -Label 'family/reissued-token-success'
    $familySession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $familyUsername -Password $familyPassword -Label 'login/family'
    if ($null -eq $familySession) { Stop-Setup 'Family session unavailable.' }

    # A separate Family Invitation exercises explicit Admin revoke/reissue.
    # Revocation releases the Seat, generation increases on reissue, and the
    # old raw token remains invalid even when replayed with fresh credentials.
    $myClassmate = Invoke-Http -Uri $myUri -Session $classmateSession
    $myToken = Get-CsrfToken -Response $myClassmate -Label 'family/revoke-my-form'
    $revokableIssue = Invoke-Http -Uri $myUri -Session $classmateSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$myToken; action='issue_family_invitation'
    }
    $revokableCode = Get-OneTimeCode -Response $revokableIssue -CssClass 'ca-public__secret' -Label 'family/revokable-issue'
    if ($null -eq $revokableCode) { Stop-Setup 'Revokable Family Invitation unavailable.' }
    $secrets.Add($revokableCode)
    $beforeRevoke = Invoke-Fixture -Action state -RunId $script:runId
    $acceptedReissueState = @($beforeRevoke.tokens | Where-Object {
        [int]$_.seat_id -eq [int]$expiredFamily.seat_id -and $_.purpose -eq 'FAMILY_INVITE' -and $_.state -eq 'CONSUMED'
    })
    if ($acceptedReissueState.Count -ne 1 -or [int]$acceptedReissueState[0].generation -ne ([int]$expiredFamily.generation + 1)) {
        Add-Failure 'family/expiry-reissue-generation' 'replacement after expiry did not increment generation exactly once and consume successfully'
    }
    $revokableToken = @($beforeRevoke.tokens | Where-Object { $_.purpose -eq 'FAMILY_INVITE' -and $_.state -eq 'ISSUED' } | Sort-Object id -Descending | Select-Object -First 1)
    if ($revokableToken.Count -ne 1) { Stop-Setup 'Could not resolve revokable Family Invitation metadata.' }
    $invitationsPage = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession
    $invitationAdminToken = Get-CsrfToken -Response $invitationsPage -Label 'family/admin-revoke-form'
    $revokeFamily = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$invitationAdminToken; action='revoke_family_invitation'; token_id=[int]$revokableToken[0].id; reason='Phase1 explicit Family Invitation revoke'
    }
    Assert-Status $revokeFamily @(303) 'family/admin-revoke' | Out-Null
    Invoke-FamilyAcceptance -FamilyUri $familyUri -Origin $origin -InvitationCode $revokableCode -Username "${userPrefix}revoked_family" -Password $invalidReusePassword -RealName "Revoked Family $runUpper" -ExpectSuccess $false -Label 'family/revoked-token-denied'
    $afterRevoke = Invoke-Fixture -Action state -RunId $script:runId
    $revokedTokenState = @($afterRevoke.tokens | Where-Object { [int]$_.id -eq [int]$revokableToken[0].id })
    $revokedSeatState = @($afterRevoke.classmate.seats | Where-Object { [int]$_.id -eq [int]$revokableToken[0].seat_id })
    if ($revokedTokenState.Count -ne 1 -or $revokedTokenState[0].state -ne 'REVOKED' -or $revokedSeatState.Count -ne 1 -or $revokedSeatState[0].state -ne 'AVAILABLE') {
        Add-Failure 'family/revoke-releases-seat' 'Admin revoke did not immediately invalidate the token and release its Seat'
    }
    $familyRevokeAudit = @($afterRevoke.audit | Where-Object { $_.action -eq 'INVITATION_REVOKE' -and $_.result -eq 'SUCCESS' })
    if ($familyRevokeAudit.Count -ne 1) { Add-Failure 'audit/family-revoke' 'Admin Family Invitation revoke did not create an Audit event' }
    $invitationsPage = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession
    $invitationAdminToken = Get-CsrfToken -Response $invitationsPage -Label 'family/admin-second-reissue-form'
    $secondReissue = Invoke-Http -Uri $adminInvitationsUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$invitationAdminToken; action='reissue_family_invitation'; seat_id=[int]$revokableToken[0].seat_id; reason='Phase1 revoked Family Invitation reissue'
    }
    $secondReissueCode = Get-OneTimeCode -Response $secondReissue -CssClass 'ca-public__secret' -Label 'family/admin-second-reissue'
    if ($null -eq $secondReissueCode) { Stop-Setup 'Second Admin Family Invitation reissue unavailable.' }
    $secrets.Add($secondReissueCode)

    # Seed one exact post-Core saga failure through the database fixture. The
    # Dashboard/System page must block production and expose a bounded repair;
    # the real HTTP action revokes Core credentials/groups before compensating
    # InnoDB state and releasing the Seat.
    $myClassmate = Invoke-Http -Uri $myUri -Session $classmateSession
    $myToken = Get-CsrfToken -Response $myClassmate -Label 'provisioning/incident-my-form'
    $incidentIssue = Invoke-Http -Uri $myUri -Session $classmateSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$myToken; action='issue_family_invitation'
    }
    $incidentCode = Get-OneTimeCode -Response $incidentIssue -CssClass 'ca-public__secret' -Label 'provisioning/incident-invitation'
    if ($null -eq $incidentCode) { Stop-Setup 'Provisioning incident Invitation unavailable.' }
    $secrets.Add($incidentCode)
    $incident = Invoke-Fixture -Action seed-provisioning-incident -RunId $script:runId -Environment @{ CI_TEST_INCIDENT_PASSWORD = $incidentPassword }
    $systemBlocked = Invoke-Http -Uri $adminSystemUri -Session $adminSession
    Assert-Status $systemBlocked @(200) 'provisioning/system-blocked' | Out-Null
    if ($systemBlocked.Text -notmatch 'PRODUCTION BLOCKED' -or $systemBlocked.Text -notmatch 'PROVISIONING_INCIDENT' -or $systemBlocked.Text -notmatch ('name=["'']operation_id["''][^>]*value=["'']' + [regex]::Escape([string]$incident.operation_id) + '["'']')) {
        Add-Failure 'provisioning/incident-visible' 'repairable saga failure was not visible as a production blocker with a bounded action'
    }
    $repairToken = Get-CsrfToken -Response $systemBlocked -Label 'provisioning/repair-form'
    $repair = Invoke-Http -Uri $adminSystemUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$repairToken; action='compensate_provisioning'; operation_id=[int]$incident.operation_id; reason='Phase1 safe post-Core compensation acceptance'
    }
    Assert-Status $repair @(303) 'provisioning/repair-action' | Out-Null
    $repairRetry = Invoke-Http -Uri $adminSystemUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$repairToken; action='compensate_provisioning'; operation_id=[int]$incident.operation_id; reason='Phase1 idempotent compensation retry'
    }
    Assert-Status $repairRetry @(303) 'provisioning/repair-idempotent-retry' | Out-Null
    $afterRepair = Invoke-Fixture -Action state -RunId $script:runId
    $repairedOperation = @($afterRepair.operations | Where-Object { [int]$_.id -eq [int]$incident.operation_id })
    if ($repairedOperation.Count -ne 1 -or $repairedOperation[0].state -ne 'COMPENSATED' -or $repairedOperation[0].account_state -ne 'DELETED' -or $repairedOperation[0].seat_state -ne 'AVAILABLE') {
        Add-Failure 'provisioning/repair-state' 'safe compensation did not close operation/account and release the Seat'
    }
    if ([int]$afterRepair.incident_tombstone.group_count -ne 0 -or [int]$afterRepair.incident_tombstone.active_auth_keys -ne 0) {
        Add-Failure 'provisioning/core-quarantine' 'compensated Core tombstone retained a group or active auth key'
    }
    $repairAudit = @($afterRepair.audit | Where-Object { $_.action -eq 'MANUAL_COMPENSATION' -and $_.result -eq 'SUCCESS' })
    if ($repairAudit.Count -ne 1) { Add-Failure 'audit/manual-compensation' 'safe compensation did not create an Audit event' }
    $repairAttemptAudit = @($afterRepair.audit | Where-Object { $_.action -eq 'MANUAL_COMPENSATION_ATTEMPT' -and $_.result -eq 'SUCCESS' })
    if ($repairAttemptAudit.Count -ne 1) { Add-Failure 'audit/manual-compensation-attempt' 'Core quarantine was not preceded by a durable Audit event' }
    Invoke-FamilyAcceptance -FamilyUri $familyUri -Origin $origin -InvitationCode $incidentCode -Username "${userPrefix}incident_replay" -Password $invalidReusePassword -RealName "Incident Replay $runUpper" -ExpectSuccess $false -Label 'provisioning/reserved-token-revoked'

    $myClassmate = Invoke-Http -Uri $myUri -Session $classmateSession
    $myToken = Get-CsrfToken -Response $myClassmate -Label 'provisioning/stale-my-form'
    $staleIssue = Invoke-Http -Uri $myUri -Session $classmateSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$myToken; action='issue_family_invitation'
    }
    $staleCode = Get-OneTimeCode -Response $staleIssue -CssClass 'ca-public__secret' -Label 'provisioning/stale-invitation'
    if ($null -eq $staleCode) { Stop-Setup 'Stale provisioning Invitation unavailable.' }
    $secrets.Add($staleCode)
    $stale = Invoke-Fixture -Action seed-stale-provisioning -RunId $script:runId
    $staleSystem = Invoke-Http -Uri $adminSystemUri -Session $adminSession
    Assert-Status $staleSystem @(200) 'provisioning/stale-system-blocked' | Out-Null
    # Keep this Windows PowerShell 5.1 script ASCII-only. Decode the expected
    # localized label at runtime because UTF-8 without a BOM is parsed as the
    # active ANSI/DBCS code page by powershell.exe 5.1.
    $staleIncidentCountMarkup = [Text.Encoding]::UTF8.GetString(
        [Convert]::FromBase64String('6ZW/5pyfIFByb3Zpc2lvbmluZ++8iOaTjeS9nCAvIOi0puWPtyAvIOW4reS9je+8iTwvdGg+PHRkPjEgLyAxIC8gMQ==')
    )
    if ($staleSystem.Text -notmatch 'PRODUCTION BLOCKED' -or $staleSystem.Text -notmatch 'PROVISIONING_INCIDENT' -or $staleSystem.Text -notmatch [regex]::Escape($staleIncidentCountMarkup)) {
        Add-Failure 'provisioning/stale-visible' 'long-running operation/account/Seat counts were not visible as a production blocker'
    }
    if ($staleSystem.Text -match ('name=["'']operation_id["''][^>]*value=["'']' + [regex]::Escape([string]$stale.operation_id) + '["'']')) {
        Add-Failure 'provisioning/stale-not-auto-repairable' 'ambiguous PREPARED incident incorrectly exposed an automatic compensation action'
    }

    # Anonymous activation returns independent credentials exactly once.
    $myClassmate = Invoke-Http -Uri $myUri -Session $classmateSession
    $myToken = Get-CsrfToken -Response $myClassmate -Label 'anonymous/my-form'
    if ($null -eq $myToken) { Stop-Setup 'Anonymous activation form unavailable.' }
    $anonymousIssue = Invoke-Http -Uri $myUri -Session $classmateSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$myToken; action='activate_anonymous'
    }
    if (-not (Assert-Status $anonymousIssue @(200) 'anonymous/activate')) { Stop-Setup 'Anonymous activation failed.' }
    $anonymousMatches = [regex]::Matches(
        $anonymousIssue.Text,
        '<code[^>]*class=["''][^"'']*ca-public__secret[^"'']*["''][^>]*>([^<]+)</code>',
        'IgnoreCase'
    )
    if ($anonymousMatches.Count -ne 2) {
        Add-Pending 'anonymous/activate' 'one-time Anonymous credential surface is not implemented'
        Stop-Setup 'Anonymous credentials could not be parsed.'
    }
    $anonymousUsername = [Net.WebUtility]::HtmlDecode($anonymousMatches[0].Groups[1].Value).Trim()
    $anonymousPassword = [Net.WebUtility]::HtmlDecode($anonymousMatches[1].Groups[1].Value).Trim()
    if ($anonymousUsername -notmatch '^anon_[a-f0-9]{20}$' -or $anonymousPassword.Length -lt 24) {
        Stop-Setup 'Anonymous credentials have an unexpected shape.'
    }
    $secrets.Add($anonymousPassword)
    $anonymousSession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $anonymousUsername -Password $anonymousPassword -Label 'login/anonymous'
    if ($null -eq $anonymousSession) { Stop-Setup 'Anonymous session unavailable.' }

    # Only SYSTEM_ADMIN may cross either Class Admin URL shape. This exercises
    # direct URLs rather than merely checking whether navigation was hidden.
    $roleSessions = [ordered]@{
        CLASSMATE=$classmateSession; TEACHER=$teacherSession; FAMILY=$familySession; ANONYMOUS=$anonymousSession
    }
    foreach ($entry in $roleSessions.GetEnumerator()) {
        Assert-Status (Invoke-Http -Uri $adminDashboardUri -Session $entry.Value) @(403) "admin/$($entry.Key.ToLowerInvariant())-alias-denied" | Out-Null
        Assert-Status (Invoke-Http -Uri $adminDirectUri -Session $entry.Value) @(403) "admin/$($entry.Key.ToLowerInvariant())-direct-denied" | Out-Null
    }

    # The ClassIdentity fail-closed denial path intentionally destroys the
    # offending ordinary session. Reauthenticate over the real login surface
    # before proving ordinary My/media permissions and later freeze revocation.
    $classmateSession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $classmateUsername -Password $classmatePassword -Label 'login/classmate-after-admin-denial'
    $familySession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $familyUsername -Password $familyPassword -Label 'login/family-after-admin-denial'
    $anonymousSession = New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $anonymousUsername -Password $anonymousPassword -Label 'login/anonymous-after-admin-denial'
    if ($null -eq $classmateSession -or $null -eq $familySession -or $null -eq $anonymousSession) {
        Stop-Setup 'Role session could not be re-established after the intentional Admin denial logout.'
    }

    # My for Anonymous must not expose its underlying Identity graph. Numeric
    # ids are checked again after the DB state snapshot below.
    $anonymousMy = Invoke-Http -Uri $myUri -Session $anonymousSession
    Assert-Status $anonymousMy @(200) 'anonymous/my-allowed' | Out-Null
    if ($anonymousMy.Text -notmatch 'class=["'']ca-public__summary["'']' -or $anonymousMy.Text -match [regex]::Escape($classmateRoster) -or $anonymousMy.Text -match "Synthetic Classmate $runUpper") {
        Add-Failure 'anonymous/my-redaction' 'ordinary Anonymous HTML exposed or failed to redact the Identity mapping'
    }
    foreach ($identifierName in @('identity_id','seat_id','account_id','principal_id','piwigo_user_id')) {
        if ($anonymousMy.Text -match ('(?i)(?:data-|["''])?' + [regex]::Escape($identifierName) + '(?:["'']|\s|=|:)')) {
            Add-Failure 'anonymous/my-redaction' "ordinary Anonymous HTML contains internal identifier label $identifierName"
        }
    }

    # Resolve one derivative through the real viewer as Classmate. The exact
    # URLs are then replayed unchanged as Family, proving URL != authorization.
    $pictureUri = [Uri]::new($baseUri, "picture.php?/$livingImageId")
    $picture = Invoke-Http -Uri $pictureUri -Session $classmateSession
    Assert-Status $picture @(200) 'media/classmate-living-viewer' | Out-Null
    $mainTag = [regex]::Match($picture.Text, '<img(?=[^>]*\bid=["'']theMainImage["''])[^>]*>', 'IgnoreCase')
    $previewMatch = if ($mainTag.Success) { [regex]::Match($mainTag.Value, '\bsrc=["'']([^"'']+)["'']', 'IgnoreCase') } else { $null }
    if ($null -eq $previewMatch -or -not $previewMatch.Success) {
        Add-Pending 'media/living-preview' 'Piwigo viewer did not expose the mature protected preview URL'
        Stop-Setup 'Known LIVING preview URL unavailable.'
    }
    $livingPreviewUri = ConvertTo-AbsoluteUri -BaseUri $baseUri -Reference $previewMatch.Groups[1].Value
    $livingOriginalUri = [Uri]::new($baseUri, $livingOriginalPath)
    Assert-MediaAllowed (Invoke-Http -Uri $livingPreviewUri -Session $classmateSession -MaxBodyBytes 128) 'media/classmate-living-preview-allow'
    Assert-MediaAllowed (Invoke-Http -Uri $livingOriginalUri -Session $classmateSession -MaxBodyBytes 128) 'media/classmate-living-original-allow'
    Assert-MediaDenied (Invoke-Http -Uri $livingPreviewUri -Session $familySession -MaxBodyBytes 128) 'media/family-known-living-preview-deny'
    Assert-MediaDenied (Invoke-Http -Uri $livingOriginalUri -Session $familySession -MaxBodyBytes 128) 'media/family-known-living-original-deny'

    # Database invariants before freeze: SYSTEM_ADMIN has no Seat/Account;
    # formal and Anonymous accounts are independent; Teacher remains singleton.
    $stateBeforeFreeze = Invoke-Fixture -Action state -RunId $script:runId
    if ([int]$stateBeforeFreeze.csrf_identity_count -ne 0) { Add-Failure 'csrf/no-mutation' 'a rejected CSRF/Origin probe mutated an Identity' }
    $systemAdmin = $stateBeforeFreeze.system_admin
    if ($systemAdmin.principal_type -ne 'SYSTEM_ACCOUNT' -or $systemAdmin.system_role -ne 'SYSTEM_ADMIN' -or $null -ne $systemAdmin.account_id -or [int]$systemAdmin.account_links -ne 0 -or [int]$systemAdmin.seat_links -ne 0) {
        Add-Failure 'admin/system-account-not-seat' 'SYSTEM_ADMIN is not an independent System Account'
    }
    $classmateSeats = @($stateBeforeFreeze.classmate.seats)
    $teacherSeats = @($stateBeforeFreeze.teacher.seats)
    if ($teacherSeats.Count -ne 1 -or $teacherSeats[0].seat_type -ne 'TEACHER' -or $null -eq $teacherSeats[0].account_id) {
        Add-Failure 'teacher/single-account' 'Teacher Identity does not have exactly one bound Teacher Seat'
    }
    $formalSeat = @($classmateSeats | Where-Object { $_.seat_type -eq 'CLASSMATE' })
    $familySeats = @($classmateSeats | Where-Object { $_.seat_type -eq 'FAMILY' -and $null -ne $_.account_id })
    $anonymousSeats = @($classmateSeats | Where-Object { $_.seat_type -eq 'ANONYMOUS' })
    if ($formalSeat.Count -ne 1 -or $familySeats.Count -ne 1 -or $anonymousSeats.Count -ne 1 -or $null -eq $anonymousSeats[0].account_id) {
        Add-Failure 'identity/seat-bindings' 'Classmate, one Family and one Anonymous account graph was not materialized'
    } elseif ([int]$anonymousSeats[0].piwigo_user_id -eq [int]$formalSeat[0].piwigo_user_id -or [int]$anonymousSeats[0].account_id -eq [int]$formalSeat[0].account_id) {
        Add-Failure 'anonymous/independent-account' 'Anonymous Seat shares an Account or Core user with Classmate'
    } else {
        foreach ($internalId in @($anonymousSeats[0].id, $anonymousSeats[0].account_id, $anonymousSeats[0].principal_id, $anonymousSeats[0].piwigo_user_id)) {
            $idText = [regex]::Escape([string]$internalId)
            if ($anonymousMy.Text -match "(?i)(?:identity|seat|account|principal|user)[_-]?id[^0-9]{0,20}$idText") {
                Add-Failure 'anonymous/my-redaction' 'ordinary Anonymous HTML exposed an internal id value'
            }
        }
    }
    $tokenStates = @($stateBeforeFreeze.tokens | ForEach-Object { "$($_.purpose):$($_.generation):$($_.state)" })
    if ('CLAIM:1:REVOKED' -notin $tokenStates -or 'CLAIM:2:CONSUMED' -notin $tokenStates) {
        Add-Failure 'claim/token-lifecycle' 'reissued old Claim was not REVOKED or replacement was not CONSUMED'
    }
    $familyReissueAudit = @($stateBeforeFreeze.audit | Where-Object { $_.action -eq 'FAMILY_INVITATION_REISSUE' -and $_.result -eq 'SUCCESS' })
    if ($familyReissueAudit.Count -ne 1 -or [int]$familyReissueAudit[0].event_count -lt 2) {
        Add-Failure 'audit/family-reissue' 'Admin Family Invitation reissues were not fully audited'
    }
    $revokedSeatReplacement = @($stateBeforeFreeze.tokens | Where-Object {
        [int]$_.seat_id -eq [int]$revokableToken[0].seat_id -and $_.purpose -eq 'FAMILY_INVITE' -and $_.state -eq 'ISSUED'
    })
    if ($revokedSeatReplacement.Count -ne 1 -or [int]$revokedSeatReplacement[0].generation -ne ([int]$revokableToken[0].generation + 1)) {
        Add-Failure 'family/revoke-reissue-generation' 'replacement after revoke did not increment generation exactly once'
    }

    # Freeze through the Admin Console after a real session and URLs already
    # exist. The old cookie must immediately lose both page and media access.
    $classmateDetail = Invoke-Http -Uri $classmateLocation -Session $adminSession
    $freezeToken = Get-CsrfToken -Response $classmateDetail -Label 'freeze/form'
    if ($null -eq $freezeToken) { Stop-Setup 'Freeze form unavailable.' }
    $freeze = Invoke-Http -Uri $adminIdentitiesUri -Session $adminSession -Method POST -Origin $origin.AbsoluteUri.TrimEnd('/') -Form @{
        pwg_token=$freezeToken; action='freeze_identity'; identity_id=$classmateIdentityId; reason='Phase1 immediate session revocation acceptance'
    }
    Assert-Status $freeze @(303) 'freeze/admin-action' | Out-Null
    Assert-ProtectedPageDenied (Invoke-Http -Uri $myUri -Session $classmateSession) 'freeze/old-session-page-denied'
    Assert-MediaDenied (Invoke-Http -Uri $livingPreviewUri -Session $classmateSession -MaxBodyBytes 128) 'freeze/old-session-media-denied'
    [void](New-AuthenticatedSession -WebServiceUri $webServiceUri -Username $classmateUsername -Password $classmatePassword -Label 'freeze/new-login-denied' -ExpectDenied)
    $stateAfterFreeze = Invoke-Fixture -Action state -RunId $script:runId
    if ($stateAfterFreeze.classmate.state -ne 'FROZEN') { Add-Failure 'freeze/database-state' 'Identity state did not become FROZEN' }
    $freezeAudit = @($stateAfterFreeze.audit | Where-Object { $_.action -eq 'IDENTITY_FREEZE' -and $_.result -eq 'SUCCESS' })
    if ($freezeAudit.Count -ne 1 -or [int]$freezeAudit[0].event_count -lt 1) { Add-Failure 'audit/freeze' 'Freeze did not create a successful Audit event' }

    # Exact-value persistence scan covers every text/blob/json DB column and
    # known PHP session/Piwigo log directories. Docker stdout/stderr is checked
    # separately without ever printing a matching value.
    $secretJson = ConvertTo-Json -Compress -InputObject @($secrets)
    $secretB64 = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($secretJson))
    $secretScan = Invoke-Fixture -Action assert-no-secrets -RunId $script:runId -Environment @{ CI_TEST_SECRETS_B64 = $secretB64 }
    if ([int]$secretScan.database_secret_matches -ne 0 -or [int]$secretScan.session_log_secret_matches -ne 0) {
        Add-Failure 'secrets/persistence' 'raw token/password found in persistent storage'
    }
    $logArguments = @($script:composeBase + @('logs', '--no-color', '--since', $logsSince, 'piwigo', 'db'))
    $logOutput = (@(& wsl.exe @logArguments 2>&1) -join "`n")
    if ($LASTEXITCODE -ne 0) {
        Add-Pending 'secrets/container-logs' 'container log history could not be inspected'
    } else {
        foreach ($secret in $secrets) {
            if ($logOutput.IndexOf($secret, [StringComparison]::Ordinal) -ge 0) {
                Add-Failure 'secrets/container-logs' 'a raw transient secret was found in container logs'
                break
            }
        }
    }
} catch {
    Add-Failure 'suite/runtime' $_.Exception.Message
} finally {
    foreach ($lease in @($coreProfileAdminLease, $coreUsersAdminLease, $adminLease)) {
        if ($null -ne $lease) {
            try { Remove-ClassArchiveSystemAdminSession -Lease $lease }
            catch { Add-Failure 'cleanup/admin-session' 'exact SYSTEM_ADMIN test-session revocation failed' }
        }
    }
    if ($script:runId -match '^[a-f0-9]{12}$') {
        try {
            $cleanupEnvironment = @{}
            if ($null -ne $script:baselineImageCount) {
                $cleanupEnvironment['CI_TEST_BASELINE_IMAGE_COUNT'] = [string]$script:baselineImageCount
            }
            [void](Invoke-Fixture -Action cleanup -RunId $script:runId -Environment $cleanupEnvironment)
        } catch {
            Add-Failure 'cleanup' 'namespace-bounded fixture cleanup failed; run the documented cleanup command before retrying'
        }
    }
}

if ($script:failures.Count -gt 0) {
    [Console]::Error.WriteLine("CLASS_IDENTITY_HTTP=FAIL probes=$($script:probeCount) failures=$($script:failures.Count) pending=$($script:pending.Count)")
    foreach ($failure in $script:failures | Select-Object -First 30) { [Console]::Error.WriteLine(" - $failure") }
    foreach ($item in $script:pending | Select-Object -First 20) { [Console]::Error.WriteLine(" - PENDING $item") }
    exit 1
}
if ($script:pending.Count -gt 0) {
    [Console]::Error.WriteLine("CLASS_IDENTITY_HTTP=PENDING probes=$($script:probeCount) pending=$($script:pending.Count)")
    foreach ($item in $script:pending | Select-Object -First 30) { [Console]::Error.WriteLine(" - $item") }
    exit 3
}

Write-Output "CLASS_IDENTITY_HTTP=PASS probes=$($script:probeCount) cleanup=verified secrets=verified"
