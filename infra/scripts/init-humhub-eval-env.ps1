[CmdletBinding()]
param(
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$examplePath = Join-Path $projectRoot '.env.example'
$envPath = Join-Path $projectRoot '.env'

if ((Test-Path -LiteralPath $envPath) -and -not $Force) {
    Write-Host '.env already exists; preserving it. Never use -Force when its MariaDB volume is initialized.'
    exit 0
}

$databaseVolume = 'class_archive_mariadb_data'
& wsl.exe -d Ubuntu -- docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Engine must be running so existing database data can be checked before generating .env.'
}

$databaseMountPoint = (& wsl.exe -d Ubuntu -- docker volume inspect --format '{{.Mountpoint}}' $databaseVolume 2>$null)
if ($LASTEXITCODE -eq 0) {
    $databaseMountPoint = $databaseMountPoint.Trim()
    if (
        -not $databaseMountPoint.StartsWith('/var/lib/docker/volumes/') `
        -or -not $databaseMountPoint.EndsWith('/_data')
    ) {
        throw "Refusing to inspect unexpected Docker volume mount point for $databaseVolume."
    }

    & wsl.exe -d Ubuntu -- test -d "$databaseMountPoint/mysql"
    if ($LASTEXITCODE -eq 0) {
        throw "The persistent database volume $databaseVolume is initialized. Restore its matching .env from secure backup; never regenerate database credentials with -Force."
    }
}

function New-Secret([int]$ByteCount = 36) {
    $bytes = New-Object byte[] $ByteCount
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $generator.GetBytes($bytes)
    }
    finally {
        $generator.Dispose()
    }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

$content = [IO.File]::ReadAllText($examplePath)
$replacements = @{
    '__GENERATE_DB_PASSWORD__' = New-Secret
    '__GENERATE_DB_ROOT_PASSWORD__' = New-Secret
    '__GENERATE_ADMIN_PASSWORD__' = New-Secret 24
    '__GENERATE_CLAIM_PEPPER__' = New-Secret 48
    '__GENERATE_PSEUDONYM_SECRET__' = New-Secret 48
}

foreach ($entry in $replacements.GetEnumerator()) {
    $content = $content.Replace($entry.Key, $entry.Value)
}

[IO.File]::WriteAllText($envPath, $content, [Text.UTF8Encoding]::new($false))
Write-Host 'Created .env with cryptographically random local secrets. The file is ignored by Git.'
