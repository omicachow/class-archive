<#
.SYNOPSIS
Bootstraps the pinned localhost Piwigo stack without persisting an admin password.

.PARAMETER AdminPasswordFile
Optional UTF-8 regular file protected to owner, SYSTEM and Administrators.
Fresh installs consume it once. An installed stack consumes it only for an
explicit staged webmaster-password recovery. Once successfully read and
validated it is strictly deleted in finally, whether later installation/reset
succeeds or fails. Retry uses the no-echo prompt or a newly protected one-time
file; normal repeat bootstrap after ClassIdentity needs no password.
#>
[CmdletBinding()]
param(
    [string]$AdminPasswordFile = ''
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$devScript = Join-Path $PSScriptRoot 'dev.ps1'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env.piwigo. Run infra\scripts\init-dev-env.ps1 first.'
}
Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
if ([IO.File]::ReadAllText($envPath) -match '(?m)^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=') {
    throw 'Refusing long-lived PIWIGO_ADMIN_PASSWORD in .env.piwigo; run remove-admin-password-from-env.ps1.'
}

function Read-DotEnv {
    param([Parameter(Mandatory = $true)][string]$Path)

    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) {
            continue
        }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) {
            throw "Invalid .env.piwigo line; expected KEY=VALUE."
        }
        $key = $trimmed.Substring(0, $separator)
        $value = $trimmed.Substring($separator + 1)
        if ($key -notmatch '^[A-Z][A-Z0-9_]*$') {
            throw "Invalid .env.piwigo key: $key"
        }
        $values[$key] = $value
    }
    return $values
}

function Require-Value {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Values,
        [Parameter(Mandatory = $true)][string]$Key
    )
    if (-not $Values.ContainsKey($Key) -or [string]::IsNullOrWhiteSpace($Values[$Key])) {
        throw "Missing required .env.piwigo value: $Key"
    }
    if ($Values[$Key] -like '__GENERATE_*__') {
        throw ".env.piwigo still contains a placeholder for $Key."
    }
    return [string]$Values[$Key]
}

function ConvertFrom-ClassArchiveSecureString {
    param([Parameter(Mandatory = $true)][Security.SecureString]$Value)
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

function Assert-AdminPasswordValue {
    param([Parameter(Mandatory = $true)][string]$Value)
    if ($Value.Length -lt 16 -or $Value.Length -gt 256 -or $Value -match '[\r\n\x00]') {
        throw 'The fresh-install administrator password must be 16-256 characters with no control-line separator.'
    }
    if ($Value -like '__GENERATE_*__') {
        throw 'The fresh-install administrator password is still a placeholder.'
    }
}

function Test-ClassArchiveFixedTimeEqual {
    param([Parameter(Mandatory = $true)][string]$Left, [Parameter(Mandatory = $true)][string]$Right)
    $leftBytes = [Text.Encoding]::UTF8.GetBytes($Left)
    $rightBytes = [Text.Encoding]::UTF8.GetBytes($Right)
    if ($leftBytes.Length -ne $rightBytes.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $leftBytes.Length; $index++) {
        $difference = $difference -bor ($leftBytes[$index] -bxor $rightBytes[$index])
    }
    return $difference -eq 0
}

function Read-FreshInstallAdminPassword {
    param([string]$OneTimeFile)

    if (-not [string]::IsNullOrWhiteSpace($OneTimeFile)) {
        if (-not (Test-Path -LiteralPath $OneTimeFile -PathType Leaf)) {
            throw 'The one-time administrator password path must be a regular file.'
        }
        $resolved = (Resolve-Path -LiteralPath $OneTimeFile).Path
        $item = Get-Item -LiteralPath $resolved -Force
        $unsafeOneTimeFile = $item.PSIsContainer `
            -or $null -ne $item.LinkType `
            -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0
        if ($unsafeOneTimeFile) {
            throw 'The one-time administrator password path may not be a directory, link or reparse point.'
        }
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        $value = [IO.File]::ReadAllText($resolved, [Text.UTF8Encoding]::new($false, $true))
        if ($value.EndsWith("`r`n")) { $value = $value.Substring(0, $value.Length - 2) }
        elseif ($value.EndsWith("`n")) { $value = $value.Substring(0, $value.Length - 1) }
        Assert-AdminPasswordValue -Value $value
        return $value
    }

    $first = Read-Host 'Fresh Piwigo SYSTEM_ADMIN password' -AsSecureString
    $second = Read-Host 'Confirm fresh Piwigo SYSTEM_ADMIN password' -AsSecureString
    $firstText = ConvertFrom-ClassArchiveSecureString -Value $first
    $secondText = ConvertFrom-ClassArchiveSecureString -Value $second
    try {
        Assert-AdminPasswordValue -Value $firstText
        if (-not (Test-ClassArchiveFixedTimeEqual -Left $firstText -Right $secondText)) {
            throw 'Fresh-install administrator password confirmation did not match.'
        }
        return $firstText
    }
    finally {
        $secondText = $null
        $first.Dispose()
        $second.Dispose()
    }
}

function Remove-ConsumedAdminPasswordFile {
    param([Parameter(Mandatory = $true)][string]$Path)
    Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (Test-Path -LiteralPath $Path) {
        throw 'The consumed one-time administrator password file still exists.'
    }
}

function Invoke-Dev {
    param([Parameter(Mandatory = $true)][string]$Action)
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $devScript $Action
    if ($LASTEXITCODE -ne 0) {
        throw "dev.ps1 $Action failed with exit code $LASTEXITCODE."
    }
}

function Invoke-Compose {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    $base = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo',
        '-f', 'infra/docker-compose.yml'
    )
    & wsl.exe @($base + $Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose command failed with exit code $LASTEXITCODE."
    }
}

function Invoke-SystemAdminPasswordReset {
    param(
        [Parameter(Mandatory = $true)][string]$Username,
        [Parameter(Mandatory = $true)][string]$Password
    )
    $arguments = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/set-system-admin-password.php', $Username
    )
    $output = @($Password | & wsl.exe @arguments 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked' -notin $output) {
        throw 'The staged SYSTEM_ADMIN password hash/reset command failed.'
    }
}

function Get-SystemAdminPasswordStage {
    param([Parameter(Mandatory = $true)][string]$Username)
    $stage = Get-ComposeOutput -Arguments @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/system-admin-password-stage.php', $Username
    )
    if ($stage -notin @(
        'SYSTEM_ADMIN_PASSWORD_STAGE=PRE_CLASS_IDENTITY',
        'SYSTEM_ADMIN_PASSWORD_STAGE=CLASS_IDENTITY'
    )) {
        throw 'The SYSTEM_ADMIN password stage could not be proven.'
    }
    return $stage
}

function Get-ComposeOutput {
    param([Parameter(Mandatory = $true)][string[]]$Arguments)
    $base = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo',
        '-f', 'infra/docker-compose.yml'
    )
    $output = & wsl.exe @($base + $Arguments)
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose inspection failed with exit code $LASTEXITCODE."
    }
    return ($output -join "`n").Trim()
}

$settings = Read-DotEnv -Path $envPath
$baseUrlText = Require-Value -Values $settings -Key 'CLASS_ARCHIVE_BASE_URL'
$baseUrl = [Uri]$baseUrlText
if (
    $baseUrl.Scheme -ne 'http' `
    -or $baseUrl.Host -notin @('localhost', '127.0.0.1') `
    -or -not [string]::IsNullOrEmpty($baseUrl.AbsolutePath.Trim('/'))
) {
    throw 'Development bootstrap requires a loopback-only http CLASS_ARCHIVE_BASE_URL with no path.'
}
$requestBaseBuilder = [UriBuilder]$baseUrl
# Windows + WSL mirrored networking can resolve localhost to IPv6 while the
# Compose port is intentionally bound to IPv4 loopback only.
$requestBaseBuilder.Host = '127.0.0.1'
$requestBaseUrl = $requestBaseBuilder.Uri

$dbName = Require-Value -Values $settings -Key 'DB_NAME'
$dbUser = Require-Value -Values $settings -Key 'DB_USER'
$dbPassword = Require-Value -Values $settings -Key 'DB_PASSWORD'
$adminUsername = Require-Value -Values $settings -Key 'PIWIGO_ADMIN_USERNAME'
$adminEmail = Require-Value -Values $settings -Key 'PIWIGO_ADMIN_EMAIL'

Invoke-Dev -Action 'up'

$ready = $false
for ($attempt = 0; $attempt -lt 60; $attempt++) {
    try {
        $probe = Invoke-WebRequest -UseBasicParsing -Uri $requestBaseUrl -TimeoutSec 3
        if ($probe.StatusCode -eq 200) {
            $ready = $true
            break
        }
    }
    catch {
        Start-Sleep -Seconds 1
    }
}
if (-not $ready) {
    throw 'Piwigo did not become reachable on the loopback URL.'
}

Invoke-Compose -Arguments @(
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'sh', '-eu', '-c',
    'mkdir -p /var/www/html/piwigo/local/config && cp /workspace/infra/piwigo-config/config.inc.php /var/www/html/piwigo/local/config/config.inc.php'
)

# Install our reviewed post-init hook into the dedicated scripts volume and
# apply it immediately. The next container start will run the same hook after
# the upstream image has established nginx ACLs and volume ownership.
Invoke-Compose -Arguments @(
    'exec', '-T', 'piwigo',
    'sh', '-eu', '-c',
    'cp /workspace/infra/piwigo-config/user.sh /usr/local/bin/scripts/user.sh && chmod 0755 /usr/local/bin/scripts/user.sh && /usr/local/bin/scripts/user.sh'
)

$installState = Get-ComposeOutput -Arguments @(
    'exec', '-T', 'piwigo', 'sh', '-c',
    'if test -f /var/www/html/piwigo/local/config/database.inc.php; then echo INSTALLED; else echo MISSING; fi'
)
$installed = $installState -eq 'INSTALLED'

if (-not $installed) {
    $adminPassword = $null
    $adminPasswordFileConsumed = $false
    try {
        $adminPassword = Read-FreshInstallAdminPassword -OneTimeFile $AdminPasswordFile
        $adminPasswordFileConsumed = -not [string]::IsNullOrWhiteSpace($AdminPasswordFile)
        $installBody = @{
            dbhost = 'db'
            dbuser = $dbUser
            dbpasswd = $dbPassword
            dbname = $dbName
            prefix = 'piwigo_'
            admin_name = $adminUsername
            admin_pass1 = $adminPassword
            admin_pass2 = $adminPassword
            admin_mail = $adminEmail
            install = 'Install'
        }
        $response = Invoke-WebRequest -UseBasicParsing -Uri ([Uri]::new($requestBaseUrl, 'install.php?language=en_UK')) -Method Post -Body $installBody -TimeoutSec 60
        $installBody = $null
        if ($response.StatusCode -ne 200) {
            throw "Piwigo installer returned HTTP $($response.StatusCode)."
        }
        Invoke-Compose -Arguments @('exec', '-T', 'piwigo', 'test', '-f', '/var/www/html/piwigo/local/config/database.inc.php')

        # Replace the installer's legacy hash through Core's configured hasher
        # over CLI STDIN. This creates no high-privilege HTTP session.
        Invoke-SystemAdminPasswordReset -Username $adminUsername -Password $adminPassword
        Write-Host 'Installed pinned Piwigo Core and upgraded the administrator password hash.'
    }
    finally {
        $adminPassword = $null
        $installBody = $null
        if ($adminPasswordFileConsumed) {
            Remove-ConsumedAdminPasswordFile -Path $AdminPasswordFile
        }
    }
}
else {
    $passwordStage = Get-SystemAdminPasswordStage -Username $adminUsername
    $passwordRecoveryRequired = $passwordStage -eq 'SYSTEM_ADMIN_PASSWORD_STAGE=PRE_CLASS_IDENTITY' `
        -or -not [string]::IsNullOrWhiteSpace($AdminPasswordFile)
    if ($passwordRecoveryRequired) {
        $recoveryPassword = $null
        $recoveryFileConsumed = $false
        try {
            $recoveryPassword = Read-FreshInstallAdminPassword -OneTimeFile $AdminPasswordFile
            $recoveryFileConsumed = -not [string]::IsNullOrWhiteSpace($AdminPasswordFile)
            Invoke-SystemAdminPasswordReset -Username $adminUsername -Password $recoveryPassword
            Write-Host 'Recovered the installed webmaster password hash without persisting plaintext.'
        }
        finally {
            $recoveryPassword = $null
            if ($recoveryFileConsumed) {
                Remove-ConsumedAdminPasswordFile -Path $AdminPasswordFile
            }
        }
    }
    Write-Host 'Piwigo Core is already installed; preserving it.'
}

Invoke-Dev -Action 'extensions'
Invoke-Dev -Action 'class-plugins'
Invoke-Compose -Arguments @(
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/configure-piwigo-baseline.php'
)
Invoke-Dev -Action 'extensions-verify'
Invoke-Dev -Action 'class-plugins-verify'
Invoke-Dev -Action 'baseline-verify'

Write-Host 'Piwigo private photo-first baseline is configured and verified.'
