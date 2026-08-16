[CmdletBinding()]
param(
    [string]$Username = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'Missing ignored .env.piwigo.'
}
Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
if ([IO.File]::ReadAllText($envPath) -match '(?m)^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=') {
    throw 'Remove the legacy plaintext administrator entry before setting a new password.'
}
if ([string]::IsNullOrWhiteSpace($Username)) {
    foreach ($line in [IO.File]::ReadAllLines($envPath)) {
        if ($line.StartsWith('PIWIGO_ADMIN_USERNAME=')) {
            $Username = $line.Substring('PIWIGO_ADMIN_USERNAME='.Length)
            break
        }
    }
}
if ($Username -notmatch '^[A-Za-z0-9_.@+-]{1,100}$') {
    throw 'A bounded SYSTEM_ADMIN username is required.'
}

function ConvertFrom-CaSecureString([Security.SecureString]$Value) {
    $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($Value)
    try { return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer) }
    finally { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
}

function Test-CaEqual([string]$Left, [string]$Right) {
    $leftBytes = [Text.Encoding]::UTF8.GetBytes($Left)
    $rightBytes = [Text.Encoding]::UTF8.GetBytes($Right)
    if ($leftBytes.Length -ne $rightBytes.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $leftBytes.Length; $index++) {
        $difference = $difference -bor ($leftBytes[$index] -bxor $rightBytes[$index])
    }
    return $difference -eq 0
}

$first = Read-Host 'New SYSTEM_ADMIN password' -AsSecureString
$second = Read-Host 'Confirm new SYSTEM_ADMIN password' -AsSecureString
$firstText = $null
$secondText = $null
try {
    $firstText = ConvertFrom-CaSecureString $first
    $secondText = ConvertFrom-CaSecureString $second
    if ($firstText.Length -lt 16 -or $firstText.Length -gt 256 -or $firstText -match '[\x00\r\n]') {
        throw 'Password must be 16-256 characters with no line separator.'
    }
    if (-not (Test-CaEqual $firstText $secondText)) {
        throw 'Password confirmation did not match.'
    }

    $compose = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--',
        'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', '/workspace/infra/scripts/set-system-admin-password.php', $Username
    )
    $output = @($firstText | & wsl.exe @compose 2>&1)
    if ($LASTEXITCODE -ne 0 -or 'SYSTEM_ADMIN_PASSWORD_UPDATED sessions=revoked' -notin $output) {
        throw 'The secure SYSTEM_ADMIN password command failed.'
    }
}
finally {
    $firstText = $null
    $secondText = $null
    $first.Dispose()
    $second.Dispose()
}

Write-Host 'SYSTEM_ADMIN password hash updated; all prior credentials were revoked.'
