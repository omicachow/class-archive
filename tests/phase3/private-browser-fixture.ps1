[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('prepare', 'rotate')]
    [string]$Action = 'prepare',

    [ValidateSet('private', 'synthetic')]
    [string]$Environment = 'private',

    [string]$CredentialFile = ''
)

# Local-only synthetic principals for Phase 3 browser QA. The private and
# canonical environments use the same role workflow but distinct compose
# state, ports and ignored credential roots. Passwords are passed through an
# owner-only file consumed by Piwigo; they never appear in argv, environment
# values, stdout or Git.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runtimeRelative = if ($Environment -eq 'private') { '.codex-work\private-real-qa\runtime\browser' } else { '.codex-work\runtime\phase3-browser' }
$fixtureLabel = if ($Environment -eq 'private') { 'PRIVATE' } else { 'SYNTHETIC' }
$privateRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot $runtimeRelative))
$compose = if ($Environment -eq 'private') {
    @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', 'infra/private-qa/.env.piwigo',
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-qa/docker-compose.override.yml',
        '-p', 'class_archive_private_qa_piwigo'
    )
} else {
    @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec',
        'docker', 'compose', '--env-file', '.env.piwigo',
        '-f', 'infra/docker-compose.yml'
    )
}
$corePort = if ($Environment -eq 'private') { 8190 } else { 8090 }
$wsUri = [Uri]("http://127.0.0.1:$corePort/ws.php?format=json")
$photosUri = [Uri]("http://127.0.0.1:$corePort/api/photos")

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

function New-SecretText {
    $bytes = New-Object byte[] 36
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Assert-PrivateFile([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    if (-not $full.StartsWith($privateRoot + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'private_browser_path_outside_runtime'
    }
    & git -C $projectRoot check-ignore --quiet --no-index -- $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    if ($LASTEXITCODE -ne 0) { throw 'private_browser_path_not_ignored' }
    if (@(& git -C $projectRoot ls-files -- $full.Substring($projectRoot.Length + 1).Replace('\', '/')).Count -ne 0) {
        throw 'private_browser_path_tracked'
    }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
}

function Write-OwnerOnly([string]$Path, [string]$Value) {
    if (Test-Path -LiteralPath $Path) { throw 'private_browser_output_exists' }
    $directory = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $directory -PathType Container)) { [void][IO.Directory]::CreateDirectory($directory) }
    [IO.File]::WriteAllText($Path, $Value, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-PrivateFile $Path
}

function Invoke-Piwigo([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& wsl.exe @($compose + $Arguments) 2>&1)
        $code = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) { throw 'private_browser_piwigo_command_failed' }
    return [string]::Join("`n", $lines)
}

function Provision-Fixtures([string]$Password, [string]$Run, [string]$HostPassword) {
    if ($Password -notmatch '^[A-Za-z0-9._~-]{32,190}$' -or $Run -notmatch '^[a-f0-9]{16}$') {
        throw 'private_browser_secret_invalid'
    }
    $container = '/tmp/class-archive-fixture-password-' + $Run + '.txt'
    Write-OwnerOnly $HostPassword $Password
    try {
        $relative = $HostPassword.Substring($projectRoot.Length + 1).Replace('\', '/')
        [void](Invoke-Piwigo @('cp', $relative, ('piwigo:' + $container)))
        [void](Invoke-Piwigo @('exec', '-T', 'piwigo', 'sh', '-lc', ('chown nginx:nginx ' + $container + ' && chmod 0600 ' + $container)))
        $result = Invoke-Piwigo @('exec', '-T', '--user', 'nginx', '-e', ('CLASS_ARCHIVE_FIXTURE_PASSWORD_FILE=' + $container), 'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php')
        if ($result.Trim() -ne 'ACCESS_FIXTURES_READY') { throw 'private_browser_fixture_rejected' }
    } finally {
        try { [void](Invoke-Piwigo @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $container)) } catch { }
        if (Test-Path -LiteralPath $HostPassword) { Remove-Item -LiteralPath $HostPassword -Force }
    }
}

function Get-PrivateSystemAdminUsername {
    if ($Environment -ne 'private') { return $null }
    $envFile = Join-Path $projectRoot 'infra\private-qa\.env.piwigo'
    foreach ($line in [IO.File]::ReadAllLines($envFile)) {
        if ($line.StartsWith('PIWIGO_ADMIN_USERNAME=')) {
            $value = $line.Substring('PIWIGO_ADMIN_USERNAME='.Length)
            if ($value -match '^[A-Za-z0-9_.@+-]{1,100}$') { return $value }
            break
        }
    }
    throw 'private_browser_admin_username_invalid'
}

function Provision-PrivateSystemAdmin([string]$Password, [string]$Username) {
    if ($Environment -ne 'private') { return }
    # Windows PowerShell 5.1 prefixes native pipeline stdin with a UTF-8 BOM.
    # Use the shared byte-exact helper so the password verified by Chromium is
    # exactly the password hashed by PHP, while the secret stays off argv.
    $native = Invoke-ClassArchiveNativeWithInput -FileName 'wsl.exe' `
        -Arguments ([string[]]($compose + @(
            'exec', '-T', '--user', 'nginx', 'piwigo',
            'php', '/workspace/infra/scripts/set-system-admin-password.php', $Username
        ))) -StandardInput $Password
    if ([int]$native.ExitCode -ne 0 -or ($native.Output -join '') -notmatch '(?m)^SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked\r?$') {
        throw 'private_browser_admin_password_update_failed'
    }
}

function Login-Classmate([string]$Password) {
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $login = Invoke-RestMethod -Uri $wsUri -Method Post -Body @{
        method = 'pwg.session.login'; username = 'fixture-classmate'; password = $Password
    } -WebSession $session -TimeoutSec 30
    if ($login.stat -ne 'ok' -or -not [bool]$login.result) { throw 'private_browser_classmate_login_failed' }
    return $session
}

$runBytes = New-Object byte[] 8
$runRng = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $runRng.GetBytes($runBytes) } finally { $runRng.Dispose() }
$run = (($runBytes | ForEach-Object { $_.ToString('x2') }) -join '')
$work = Join-Path $privateRoot $run
$passwordPath = Join-Path $work 'fixture-password.txt'
$password = New-SecretText
$rotateTarget = $null
$rotateLeaseHandle = $null

try {
    if ($Action -eq 'rotate') {
        if ([string]::IsNullOrWhiteSpace($CredentialFile)) { throw 'private_browser_credential_required' }
        $rotateTarget = (Resolve-Path -LiteralPath $CredentialFile).Path
        Assert-PrivateFile $rotateTarget
        if ([IO.Path]::GetFileName($rotateTarget) -ne 'credentials.json') { throw 'private_browser_credential_name_invalid' }
        $bytes = [IO.File]::ReadAllBytes($rotateTarget)
        if ($bytes.Length -lt 32 -or $bytes.Length -gt 65536) { throw 'private_browser_credential_size_invalid' }
        try {
            $document = ([Text.UTF8Encoding]::new($false, $true).GetString($bytes) | ConvertFrom-Json -ErrorAction Stop)
        } finally {
            [Array]::Clear($bytes, 0, $bytes.Length)
        }
        $credentialVersion = [int]$document.version
        if ($credentialVersion -notin @(1, 2) -or [string]$document.environment -ne $Environment) {
            throw 'private_browser_credential_schema_invalid'
        }
        $rolesProperty = $document.PSObject.Properties['roles']
        $adminProperty = if ($credentialVersion -eq 2 -and $null -ne $rolesProperty) {
            $rolesProperty.Value.PSObject.Properties['admin']
        } else { $null }
        $adminRole = if ($null -ne $adminProperty) { $adminProperty.Value } else { $null }
        if ($credentialVersion -eq 2 -and $null -eq $adminRole) {
            throw 'private_browser_admin_credential_missing'
        }
        $leaseProperty = if ($null -ne $adminRole) { $adminRole.PSObject.Properties['leaseHandle'] } else { $null }
        $rotateLeaseHandle = if ($null -ne $leaseProperty) { [string]$leaseProperty.Value } else { $null }
        $document = $null
        if ($credentialVersion -eq 2 -and $rotateLeaseHandle -match '^[a-f0-9]{24}$') {
            $revocation = Invoke-ClassArchiveSessionFixture -ComposeBase ([string[]]$compose) `
                -Action revoke -Handle $rotateLeaseHandle
            if (-not [bool]$revocation.ok -or (-not [bool]$revocation.revoked -and -not [bool]$revocation.absent)) {
                throw 'private_browser_admin_lease_cleanup_failed'
            }
        } elseif ($credentialVersion -eq 2 -and $Environment -eq 'private') {
            # Version-2 documents produced before lease handles were added are
            # still safely revocable: Provision-PrivateSystemAdmin below
            # rotates the password and revokes every existing admin session
            # before this owner-only file is removed. Never accept this legacy
            # shape for the synthetic environment, which has no admin role.
            $rotateLeaseHandle = $null
        } elseif ($credentialVersion -eq 2) {
            throw 'private_browser_admin_lease_invalid'
        } elseif ($Environment -ne 'synthetic') {
            throw 'private_browser_admin_lease_missing'
        }
    }

    Provision-Fixtures -Password $password -Run $run -HostPassword $passwordPath
    $adminUsername = Get-PrivateSystemAdminUsername
    if ($null -ne $adminUsername) { Provision-PrivateSystemAdmin -Password $password -Username $adminUsername }
    if ($Action -eq 'rotate') {
        Remove-Item -LiteralPath $rotateTarget -Force
        if (Test-Path -LiteralPath $rotateTarget) { throw 'private_browser_credential_cleanup_failed' }
        Write-Output ($fixtureLabel + '_BROWSER_FIXTURE=PASS action=rotate')
        exit 0
    }

    if (-not [string]::IsNullOrWhiteSpace($CredentialFile)) { throw 'private_browser_prepare_credential_forbidden' }
    $session = Login-Classmate $password
    $photos = Invoke-RestMethod -Uri $photosUri -Method Get -WebSession $session -Headers @{ Accept = 'application/json' } -TimeoutSec 60
    $living = @($photos.items | Where-Object { [string]$_.era -eq 'LIVING' -and [string]$_.id -match '^[0-9a-f-]{36}$' } | Select-Object -First 1)
    if ($photos.total -lt 1 -or $living.Count -ne 1) { throw 'private_browser_living_fixture_missing' }
    # The V4 scope browser gate needs an authority-side catalog truth that is
    # independent of the compatibility BFF it is testing.  Keep it as a
    # separate ignored, owner-only synthetic artifact so older browser
    # harnesses retain their deliberately small credential schema.  This is
    # not a public fixture or a source of role decisions at runtime.
    $scopeTruthPath = $null
    if ($Environment -eq 'synthetic') {
        $heritageIds = @($photos.items | Where-Object { [string]$_.era -eq 'HERITAGE' } | ForEach-Object { [string]$_.id })
        $livingIds = @($photos.items | Where-Object { [string]$_.era -eq 'LIVING' } | ForEach-Object { [string]$_.id })
        $allIds = @($heritageIds + $livingIds)
        if (
            [int]$photos.total -ne 72 -or
            $heritageIds.Count -lt 1 -or $livingIds.Count -lt 2 -or
            $allIds.Count -ne 72 -or
            $allIds.Count -ne [int]$photos.total -or
            @($allIds | Where-Object { $_ -notmatch '^[0-9a-f-]{36}$' }).Count -ne 0 -or
            @($allIds | ForEach-Object { $_.ToLowerInvariant() } | Select-Object -Unique).Count -ne $allIds.Count
        ) {
            throw 'synthetic_scope_truth_catalog_invalid'
        }
        $scopeTruthPath = Join-Path $work 'scope-truth.json'
        $scopeTruth = [ordered]@{
            version = 1
            environment = 'synthetic'
            heritagePhotoIds = @($heritageIds | ForEach-Object { $_.ToLowerInvariant() } | Sort-Object)
            livingPhotoIds = @($livingIds | ForEach-Object { $_.ToLowerInvariant() } | Sort-Object)
        }
        Write-OwnerOnly $scopeTruthPath ($scopeTruth | ConvertTo-Json -Compress -Depth 4)
    }
    $credentialPath = Join-Path $work 'credentials.json'
    $roles = [ordered]@{
        classmate = [ordered]@{ username = 'fixture-classmate'; password = $password }
        family = [ordered]@{ username = 'fixture-family'; password = $password }
        teacher = [ordered]@{ username = 'fixture-teacher'; password = $password }
        anonymous = [ordered]@{ username = 'fixture-anonymous'; password = $password }
    }
    if ($null -ne $adminUsername) {
        $lease = New-ClassArchiveSystemAdminSession `
            -BaseUri ([Uri]'http://127.0.0.1:8190/') `
            -ComposeBase ([string[]]$compose) `
            -AdminUsername $adminUsername
        $cookies = @($lease.Session.Cookies.GetCookies([Uri]'http://127.0.0.1:8190/') | Where-Object { $_.Name -eq 'pwg_id' })
        if ($cookies.Count -ne 1 -or [string]$cookies[0].Value -notmatch '^[A-Za-z0-9,-]{16,128}$') {
            try { Remove-ClassArchiveSystemAdminSession -Lease $lease } catch { }
            throw 'private_browser_admin_cookie_invalid'
        }
        $roles.admin = [ordered]@{
            username = $adminUsername
            cookie = [string]$cookies[0].Value
            leaseHandle = [string]$lease.Handle
        }
    }
    $credential = [ordered]@{
        version = if ($null -ne $adminUsername) { 2 } else { 1 }
        environment = $Environment
        familyDeniedPhotoId = [string]$living[0].id
        roles = $roles
    }
    Write-OwnerOnly $credentialPath ($credential | ConvertTo-Json -Compress -Depth 5)
    $scopeSuffix = if ($null -ne $scopeTruthPath) { ' scope_truth=' + $scopeTruthPath } else { '' }
    Write-Output ($fixtureLabel + '_BROWSER_FIXTURE=PASS action=prepare credential=' + $credentialPath + $scopeSuffix)
} finally {
    $password = $null
    $rotateLeaseHandle = $null
    if (Test-Path -LiteralPath $passwordPath) { Remove-Item -LiteralPath $passwordPath -Force }
}
