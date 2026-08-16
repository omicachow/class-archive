[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$examplePath = Join-Path $projectRoot '.env.example'
$envPath = Join-Path $projectRoot '.env.piwigo'

if (Test-Path -LiteralPath $envPath) {
    Write-Host '.env.piwigo already exists; preserving it.'
    exit 0
}

& wsl.exe -d Ubuntu -- docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Engine must be running so persistent data can be checked before generating secrets.'
}

function Test-VolumePath {
    param(
        [Parameter(Mandatory = $true)]
        [string]$VolumeName,
        [Parameter(Mandatory = $true)]
        [string]$RelativePath
    )

    $volumeNames = @(& wsl.exe -d Ubuntu -- docker volume ls --format '{{.Name}}')
    if ($LASTEXITCODE -ne 0) {
        throw 'Cannot list Docker volumes.'
    }
    if ($VolumeName -notin $volumeNames) {
        return $false
    }

    $mountPoint = (& wsl.exe -d Ubuntu -- docker volume inspect --format '{{.Mountpoint}}' $VolumeName)
    if ($LASTEXITCODE -ne 0) {
        throw "Cannot inspect Docker volume $VolumeName."
    }

    $mountPoint = $mountPoint.Trim()
    if (
        -not $mountPoint.StartsWith('/var/lib/docker/volumes/') `
        -or -not $mountPoint.EndsWith('/_data')
    ) {
        throw "Refusing to inspect unexpected Docker volume mount point for $VolumeName."
    }

    & wsl.exe -d Ubuntu -- test -e "$mountPoint/$RelativePath"
    return $LASTEXITCODE -eq 0
}

function Test-VolumeExists {
    param([Parameter(Mandatory = $true)][string]$VolumeName)
    $volumeNames = @(& wsl.exe -d Ubuntu -- docker volume ls --format '{{.Name}}')
    if ($LASTEXITCODE -ne 0) {
        throw 'Cannot list Docker volumes.'
    }
    return $VolumeName -in $volumeNames
}

$hasDatabase = Test-VolumePath -VolumeName 'class_archive_piwigo_db' -RelativePath 'mysql'
$hasPiwigoConfig = Test-VolumePath `
    -VolumeName 'class_archive_piwigo_data' `
    -RelativePath 'local/config/database.inc.php'

$persistentVolumes = @(
    'class_archive_piwigo_data',
    'class_archive_piwigo_uploads',
    'class_archive_piwigo_galleries',
    'class_archive_piwigo_derivatives',
    'class_archive_piwigo_db',
    'class_archive_piwigo_scripts',
    'class_archive_piwigo_backups'
)
$hasAnyPersistentVolume = $false
foreach ($volumeName in $persistentVolumes) {
    if (Test-VolumeExists -VolumeName $volumeName) {
        $hasAnyPersistentVolume = $true
        break
    }
}

if ($hasDatabase -or $hasPiwigoConfig -or $hasAnyPersistentVolume) {
    throw 'Piwigo persistent data already exists. Restore its matching .env.piwigo from secure backup; never regenerate database or derivation secrets.'
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
Write-Host 'Created ignored .env.piwigo with cryptographically random local secrets.'
