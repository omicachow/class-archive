[CmdletBinding()]
param()

# Creates the two ignored, owner-only Private QA env files from the currently
# installed local synthetic baseline. Values are never printed. The resulting
# services use dedicated project/volume/network/port identities, while cloned
# databases retain the matching local-only credentials needed for recovery.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-qa'
$sourcePiwigo = Join-Path $projectRoot '.env.piwigo'
$sourceImmich = Join-Path $projectRoot 'infra\immich-spike\.env'
$targetDirectory = Join-Path $projectRoot 'infra\private-qa'
$targetPiwigo = Join-Path $targetDirectory '.env.piwigo'
$targetImmich = Join-Path $targetDirectory '.env.immich'
$staging = Join-Path $privateRoot 'staging'
$selection = Join-Path $privateRoot 'selection\private-selection-manifest.json'
$wsl = "$env:SystemRoot\System32\wsl.exe"

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-Prepare([string]$Code) {
    Write-Output "PRIVATE_QA_ENV=FAIL code=$Code"
    exit 2
}

function Read-Environment([string]$Path) {
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    $values = @{}
    foreach ($line in [IO.File]::ReadAllLines($Path, [Text.UTF8Encoding]::new($false, $true))) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -notmatch '^([A-Z][A-Z0-9_]*)=(.*)$') { Stop-Prepare 'source_env_invalid' }
        if ($values.ContainsKey($Matches[1])) { Stop-Prepare 'source_env_duplicate' }
        $values[[string]$Matches[1]] = [string]$Matches[2]
    }
    return $values
}

function Require-Value([hashtable]$Values, [string]$Name) {
    if (-not $Values.ContainsKey($Name) -or [string]::IsNullOrWhiteSpace([string]$Values[$Name])) {
        Stop-Prepare 'source_value_missing'
    }
    $value = [string]$Values[$Name]
    if ($value.Contains("`r") -or $value.Contains("`n") -or $value.Contains("`0") -or $value -like '__*__') {
        Stop-Prepare 'source_value_invalid'
    }
    return $value
}

function Convert-ToWslPath([string]$Path) {
    $result = @(& $wsl -d Ubuntu --exec wslpath -a $Path 2>&1)
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or [string]$result[0] -notmatch '^/mnt/[a-z]/') {
        Stop-Prepare 'wsl_path_failed'
    }
    return [string]$result[0]
}

function Protect-PrivateFile([string]$Path) {
    $arguments = @(
        $Path,
        '/inheritance:r',
        '/grant:r',
        ("$env:USERNAME" + ':(F)'),
        '*S-1-5-18:(F)',
        '*S-1-5-32-544:(F)'
    )
    & icacls.exe @arguments 1>$null 2>$null
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'private_acl_write_failed' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
}

function Write-PrivateFile([string]$Path, [string[]]$Lines) {
    if (Test-Path -LiteralPath $Path) { Stop-Prepare 'target_already_exists' }
    $temporary = $Path + '.partial-' + [Guid]::NewGuid().ToString('N')
    try {
        [IO.File]::WriteAllText($temporary, ([string]::Join("`n", $Lines) + "`n"), [Text.UTF8Encoding]::new($false))
        Protect-PrivateFile $temporary
        Move-Item -LiteralPath $temporary -Destination $Path -ErrorAction Stop
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    }
    finally {
        if (Test-Path -LiteralPath $temporary -PathType Leaf) {
            Remove-Item -LiteralPath $temporary -Force -ErrorAction SilentlyContinue
        }
    }
}

try {
    foreach ($required in @($sourcePiwigo, $sourceImmich, $selection)) {
        if (-not (Test-Path -LiteralPath $required -PathType Leaf)) { Stop-Prepare 'required_file_missing' }
    }
    if (-not (Test-Path -LiteralPath $staging -PathType Container)) { Stop-Prepare 'staging_missing' }
    Protect-PrivateFile $selection
    if ((Test-Path -LiteralPath $targetPiwigo) -or (Test-Path -LiteralPath $targetImmich)) {
        Stop-Prepare 'target_already_exists'
    }
    & git -C $projectRoot check-ignore --quiet --no-index -- 'infra/private-qa/.env.piwigo'
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'target_not_ignored' }
    & git -C $projectRoot check-ignore --quiet --no-index -- 'infra/private-qa/.env.immich'
    if ($LASTEXITCODE -ne 0) { Stop-Prepare 'target_not_ignored' }

    $piwigo = Read-Environment $sourcePiwigo
    $immich = Read-Environment $sourceImmich
    $stagingWsl = Convert-ToWslPath $staging
    $selectionWsl = Convert-ToWslPath $selection

    $piwigoLines = @(
        'COMPOSE_PROJECT_NAME=class_archive_private_qa_piwigo',
        'CLASS_ARCHIVE_HTTP_PORT=8190',
        'CLASS_ARCHIVE_COMPAT_HTTP_PORT=8191',
        'CLASS_ARCHIVE_GATEWAY_NETWORK=class_archive_private_qa_gateway',
        'CLASS_ARCHIVE_BASE_URL=http://127.0.0.1:8190',
        ('CLASS_ARCHIVE_TIMEZONE=' + (Require-Value $piwigo 'CLASS_ARCHIVE_TIMEZONE')),
        ('PRIVATE_QA_STAGING_PATH=' + $stagingWsl),
        ('PRIVATE_QA_SELECTION_MANIFEST_PATH=' + $selectionWsl),
        'PIWIGO_UID=1000',
        'PIWIGO_GID=1000',
        'PIWIGO_DATA_VOLUME=class_archive_private_qa_piwigo_data',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_qa_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_qa_piwigo_galleries',
        'PIWIGO_DERIVATIVES_VOLUME=class_archive_private_qa_piwigo_derivatives',
        'PIWIGO_DB_VOLUME=class_archive_private_qa_piwigo_db',
        'PIWIGO_SCRIPTS_VOLUME=class_archive_private_qa_piwigo_scripts',
        'PIWIGO_BACKUPS_VOLUME=class_archive_private_qa_piwigo_backups',
        ('PIWIGO_IMAGE=' + (Require-Value $piwigo 'PIWIGO_IMAGE')),
        ('MARIADB_IMAGE=' + (Require-Value $piwigo 'MARIADB_IMAGE')),
        ('DB_NAME=' + (Require-Value $piwigo 'DB_NAME')),
        ('DB_USER=' + (Require-Value $piwigo 'DB_USER')),
        ('DB_PASSWORD=' + (Require-Value $piwigo 'DB_PASSWORD')),
        ('DB_ROOT_PASSWORD=' + (Require-Value $piwigo 'DB_ROOT_PASSWORD')),
        ('PIWIGO_ADMIN_USERNAME=' + (Require-Value $piwigo 'PIWIGO_ADMIN_USERNAME')),
        ('PIWIGO_ADMIN_EMAIL=' + (Require-Value $piwigo 'PIWIGO_ADMIN_EMAIL')),
        ('CLASS_ARCHIVE_CLAIM_CODE_PEPPER=' + (Require-Value $piwigo 'CLASS_ARCHIVE_CLAIM_CODE_PEPPER')),
        ('CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET=' + (Require-Value $piwigo 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET')),
        'SMTP_HOST=', 'SMTP_PORT=', 'SMTP_USERNAME=', 'SMTP_PASSWORD=', 'SMTP_ENCRYPTION='
    )
    $immichLines = @(
        'IMMICH_COMPOSE_PROJECT_NAME=class_archive_private_qa_immich',
        'CLASS_ARCHIVE_COMPAT_HTTP_PORT=8191',
        'CLASS_ARCHIVE_CORE_PUBLIC_PORT=8190',
        'CLASS_ARCHIVE_GATEWAY_NETWORK=class_archive_private_qa_gateway',
        'IMMICH_UPLOAD_VOLUME=class_archive_private_qa_immich_upload',
        'IMMICH_MODEL_CACHE_VOLUME=class_archive_private_qa_immich_model_cache',
        'IMMICH_DB_VOLUME=class_archive_private_qa_immich_db',
        'IMMICH_GATEWAY_SECRET_VOLUME=class_archive_private_qa_immich_gateway_secret',
        'PIWIGO_UPLOADS_VOLUME=class_archive_private_qa_piwigo_uploads',
        'PIWIGO_GALLERIES_VOLUME=class_archive_private_qa_piwigo_galleries',
        ('DB_PASSWORD=' + (Require-Value $immich 'DB_PASSWORD')),
        ('DB_USERNAME=' + (Require-Value $immich 'DB_USERNAME')),
        ('DB_DATABASE_NAME=' + (Require-Value $immich 'DB_DATABASE_NAME')),
        ('TZ=' + (Require-Value $immich 'TZ'))
    )

    Write-PrivateFile $targetPiwigo $piwigoLines
    Write-PrivateFile $targetImmich $immichLines
    Write-Output 'PRIVATE_QA_ENV=PASS files=2 secrets=not_logged'
    exit 0
}
catch {
    $name = $_.Exception.GetType().Name
    if ($name -notmatch '^[A-Za-z0-9]{1,64}$') { $name = 'Exception' }
    Stop-Prepare ('unexpected_' + $name)
}
