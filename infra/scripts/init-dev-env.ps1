[CmdletBinding()]
param(
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$examplePath = Join-Path $projectRoot '.env.example'
$envPath = Join-Path $projectRoot '.env'

if ((Test-Path -LiteralPath $envPath) -and -not $Force) {
    Write-Host '.env already exists; preserving it. Use -Force only when you intentionally want new local secrets.'
    exit 0
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
