[CmdletBinding()]
param(
    [switch]$RotateBootstrapPassword
)

# Fresh-core bootstrap for the isolated full private-library staging endpoint.
# The only durable plaintext is an ignored, owner-only local recovery/testing
# secret. It is never printed, committed, copied into an env file, or sent to
# a container command line.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-full'
$secretDirectory = Join-Path $privateRoot 'secrets'
$passwordPath = Join-Path $secretDirectory 'bootstrap-piwigo-admin.txt'
$envPath = Join-Path $projectRoot 'infra\private-full\.env.piwigo.staging'
$lifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Read-StrictEnvironment([string]$Path) {
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path, [Text.UTF8Encoding]::new($false, $true))) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { throw 'private_full_env_invalid' }
        if ($values.ContainsKey($Matches[1])) { throw 'private_full_env_invalid' }
        $values[$Matches[1]] = [string]$Matches[2]
    }
    foreach ($required in @('DB_NAME','DB_USER','DB_PASSWORD','PIWIGO_ADMIN_USERNAME','PIWIGO_ADMIN_EMAIL')) {
        if (-not $values.ContainsKey($required) -or [string]::IsNullOrWhiteSpace([string]$values[$required])) {
            throw 'private_full_env_incomplete'
        }
    }
    return $values
}

function New-PrivateFullPassword {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    $bytes = [byte[]]::new(48)
    # Windows PowerShell 5.1 runs on .NET Framework, where the static Fill
    # helper is unavailable. Use the same cryptographic RNG through the
    # compatible instance API instead of falling back to a predictable source.
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    $characters = foreach ($byte in $bytes) { $alphabet[$byte % $alphabet.Length] }
    return -join $characters
}

function Invoke-FullCompose([string[]]$Arguments) {
    $compose = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', 'infra/private-full/.env.piwigo.staging',
        '-f', 'infra/docker-compose.yml', '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo'
    )
    & "$env:SystemRoot\System32\wsl.exe" @($compose + $Arguments)
    if ($LASTEXITCODE -ne 0) { throw 'private_full_compose_failed' }
}

function Test-Installed {
    $lines = @(& "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- `
        docker compose --env-file infra/private-full/.env.piwigo.staging `
        -f infra/docker-compose.yml -f infra/private-full/docker-compose.override.yml `
        -p class_archive_private_full_v3_piwigo exec -T piwigo `
        sh -eu -c 'test -f /var/www/html/piwigo/local/config/database.inc.php && printf installed' 2>$null)
    return $LASTEXITCODE -eq 0 -and $lines.Count -eq 1 -and [string]$lines[0] -eq 'installed'
}

try {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $lifecycle validate | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'private_full_lifecycle_invalid' }
    if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) { throw 'private_full_env_missing' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    $envValues = Read-StrictEnvironment $envPath

    Invoke-FullCompose @('exec', '-T', '--user', 'nginx', 'piwigo', 'sh', '-eu', '-c',
        'mkdir -p /var/www/html/piwigo/local/config && cp /workspace/infra/piwigo-config/config.inc.php /var/www/html/piwigo/local/config/config.inc.php')
    Invoke-FullCompose @('exec', '-T', 'piwigo', 'sh', '-eu', '-c',
        'cp /workspace/infra/piwigo-config/user.sh /usr/local/bin/scripts/user.sh && chmod 0755 /usr/local/bin/scripts/user.sh && /usr/local/bin/scripts/user.sh')

    $installed = Test-Installed
    $password = $null
    try {
        if (-not $installed) {
            if (-not (Test-Path -LiteralPath $secretDirectory)) {
                [void](New-Item -ItemType Directory -Path $secretDirectory -Force -ErrorAction Stop)
            }
            $password = New-PrivateFullPassword
            [IO.File]::WriteAllText($passwordPath, $password + "`n", [Text.UTF8Encoding]::new($false))
            Set-ClassArchiveOwnerOnlyFileAcl -Path $passwordPath

            $body = @{
                dbhost = 'db'; dbuser = [string]$envValues.DB_USER; dbpasswd = [string]$envValues.DB_PASSWORD
                dbname = [string]$envValues.DB_NAME; prefix = 'piwigo_'; admin_name = [string]$envValues.PIWIGO_ADMIN_USERNAME
                admin_pass1 = $password; admin_pass2 = $password; admin_mail = [string]$envValues.PIWIGO_ADMIN_EMAIL; install = 'Install'
            }
            try {
                $response = Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8290/install.php?language=en_UK' -Method Post -Body $body -TimeoutSec 90
            } catch {
                throw 'private_full_piwigo_install_http_failed'
            } finally {
                $body = $null
            }
            if ($response.StatusCode -ne 200 -or -not (Test-Installed)) { throw 'private_full_piwigo_install_failed' }
            $resetOutput = @($password | & "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- `
                docker compose --env-file infra/private-full/.env.piwigo.staging `
                -f infra/docker-compose.yml -f infra/private-full/docker-compose.override.yml `
                -p class_archive_private_full_v3_piwigo exec -T --user nginx piwigo `
                php /workspace/infra/scripts/set-system-admin-password.php ([string]$envValues.PIWIGO_ADMIN_USERNAME) 2>&1)
            if ($LASTEXITCODE -ne 0 -or @($resetOutput | Where-Object { $_ -eq 'SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked' }).Count -ne 1) {
                throw 'private_full_password_hash_upgrade_failed'
            }
            $installed = $true
        } elseif ($RotateBootstrapPassword) {
            $password = New-PrivateFullPassword
            [IO.File]::WriteAllText($passwordPath, $password + "`n", [Text.UTF8Encoding]::new($false))
            Set-ClassArchiveOwnerOnlyFileAcl -Path $passwordPath
            $resetOutput = @($password | & "$env:SystemRoot\System32\wsl.exe" -d Ubuntu --cd $projectRoot -- `
                docker compose --env-file infra/private-full/.env.piwigo.staging `
                -f infra/docker-compose.yml -f infra/private-full/docker-compose.override.yml `
                -p class_archive_private_full_v3_piwigo exec -T --user nginx piwigo `
                php /workspace/infra/scripts/set-system-admin-password.php ([string]$envValues.PIWIGO_ADMIN_USERNAME) 2>&1)
            if ($LASTEXITCODE -ne 0 -or @($resetOutput | Where-Object { $_ -eq 'SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked' }).Count -ne 1) {
                throw 'private_full_password_rotation_failed'
            }
        }
    } finally {
        $password = $null
    }
    if (-not (Test-Installed)) { throw 'private_full_piwigo_install_unverified' }
    if (Test-Path -LiteralPath $passwordPath) { Assert-ClassArchiveOwnerOnlyFileAcl -Path $passwordPath }
    Write-Output ('PRIVATE_FULL_PIWIGO_BOOTSTRAP=PASS state=' + $(if ($installed) { 'READY' } else { 'UNKNOWN' }) + ' admin_secret=OWNER_ONLY')
}
catch {
    $code = if ([string]$_.Exception.Message -match '^[a-z0-9_]{1,96}$') { [string]$_.Exception.Message } else { 'private_full_bootstrap_failed' }
    Write-Output "PRIVATE_FULL_PIWIGO_BOOTSTRAP=FAIL code=$code"
    exit 2
}
