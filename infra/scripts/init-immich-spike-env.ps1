[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = Split-Path -Parent (Split-Path -Parent $PSScriptRoot)
$spikeRoot = Join-Path $projectRoot 'infra\immich-spike'
$envPath = Join-Path $spikeRoot '.env'
$piwigoEnvPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Get-LocalEnvValue([string]$path, [string]$name, [string]$fallback) {
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        return $fallback
    }

    $matches = @(
        Get-Content -LiteralPath $path -ErrorAction Stop | Where-Object {
            $_ -match ('^\s*' + [regex]::Escape($name) + '\s*=\s*([^\s#]+)\s*$')
        }
    )
    if ($matches.Count -gt 1) {
        throw "Duplicate local environment key: $name"
    }
    if ($matches.Count -eq 0) {
        return $fallback
    }
    $value = [regex]::Match([string]$matches[0], '^\s*[^=]+\s*=\s*([^\s#]+)\s*$').Groups[1].Value
    if ($value -notmatch '^[A-Za-z0-9_.-]+$') {
        throw "Unsafe local environment value for $name"
    }
    return $value
}

function New-AlphaNumericSecret([int]$length) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'.ToCharArray()
    $bytes = New-Object byte[] ($length * 2)
    # Windows PowerShell 5.1 hosts the .NET Framework API, which does not
    # provide the newer static RandomNumberGenerator.Fill method. Use the
    # compatible CSPRNG instance API so the local secret bootstrap remains
    # cryptographically strong on both Windows PowerShell and pwsh.
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($bytes)
    $characters = New-Object char[] $length
    $offset = 0
    try {
        for ($index = 0; $index -lt $length; $index++) {
            while ($bytes[$offset] -ge (256 - (256 % $alphabet.Length))) {
                $offset++
                if ($offset -ge $bytes.Length) {
                    $rng.GetBytes($bytes)
                    $offset = 0
                }
            }
            $characters[$index] = $alphabet[$bytes[$offset] % $alphabet.Length]
            $offset++
        }
    } finally {
        $rng.Dispose()
    }
    return -join $characters
}

if (Test-Path -LiteralPath $envPath -PathType Leaf) {
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    $requiredNames = @('DB_PASSWORD', 'DB_USERNAME', 'DB_DATABASE_NAME', 'PIWIGO_UPLOADS_VOLUME', 'PIWIGO_GALLERIES_VOLUME')
    foreach ($name in $requiredNames) {
        [void](Get-LocalEnvValue -path $envPath -name $name -fallback '')
    }
    if ((Get-LocalEnvValue -path $envPath -name 'DB_PASSWORD' -fallback '').Length -lt 32) {
        throw 'Existing Immich spike database password is too short.'
    }
    Write-Output 'IMMICH_SPIKE_ENV=EXISTS_VERIFIED'
    exit 0
}

if (-not (Test-Path -LiteralPath $piwigoEnvPath -PathType Leaf)) {
    throw 'Piwigo local environment is required to resolve only its volume names.'
}

$uploadVolume = Get-LocalEnvValue -path $piwigoEnvPath -name 'PIWIGO_UPLOADS_VOLUME' -fallback 'class_archive_piwigo_uploads'
$galleriesVolume = Get-LocalEnvValue -path $piwigoEnvPath -name 'PIWIGO_GALLERIES_VOLUME' -fallback 'class_archive_piwigo_galleries'
$password = New-AlphaNumericSecret -length 48
$lines = @(
    '# Generated locally by init-immich-spike-env.ps1. Never commit this file.',
    "DB_PASSWORD=$password",
    'DB_USERNAME=postgres',
    'DB_DATABASE_NAME=immich',
    "PIWIGO_UPLOADS_VOLUME=$uploadVolume",
    "PIWIGO_GALLERIES_VOLUME=$galleriesVolume",
    'TZ=Asia/Shanghai'
)

try {
    [System.IO.File]::WriteAllText($envPath, ($lines -join "`n") + "`n", [System.Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    Write-Output 'IMMICH_SPIKE_ENV=CREATED_RESTRICTED'
} catch {
    if (Test-Path -LiteralPath $envPath -PathType Leaf) {
        Remove-Item -LiteralPath $envPath -Force -ErrorAction SilentlyContinue
    }
    throw
} finally {
    $password = ''
}
