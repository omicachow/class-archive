[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
$devScript = Join-Path $PSScriptRoot 'dev.ps1'

if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env.piwigo. Run infra\scripts\init-dev-env.ps1 first.'
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
$adminPassword = Require-Value -Values $settings -Key 'PIWIGO_ADMIN_PASSWORD'

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
    if ($response.StatusCode -ne 200) {
        throw "Piwigo installer returned HTTP $($response.StatusCode)."
    }
    Invoke-Compose -Arguments @('exec', '-T', 'piwigo', 'test', '-f', '/var/www/html/piwigo/local/config/database.inc.php')
    Write-Host 'Installed pinned Piwigo Core.'
}
else {
    Write-Host 'Piwigo Core is already installed; preserving it.'
}

# The upstream installer initially writes an MD5 password hash. A successful
# Core login immediately migrates it to Piwigo's current phpass representation.
$loginBody = @{
    format = 'json'
    method = 'pwg.session.login'
    username = $adminUsername
    password = $adminPassword
}
$loginResponse = Invoke-RestMethod -Uri ([Uri]::new($requestBaseUrl, 'ws.php?format=json')) -Method Post -Body $loginBody -SessionVariable adminSession -TimeoutSec 30
if ($loginResponse.stat -ne 'ok' -or -not $loginResponse.result) {
    throw 'Administrator bootstrap login failed.'
}

Invoke-Dev -Action 'extensions'
Invoke-Compose -Arguments @(
    'exec', '-T', '--user', 'nginx', 'piwigo',
    'php', '/workspace/infra/scripts/configure-piwigo-baseline.php'
)
Invoke-Dev -Action 'extensions-verify'
Invoke-Dev -Action 'baseline-verify'

Write-Host 'Piwigo private photo-first baseline is configured and verified.'
