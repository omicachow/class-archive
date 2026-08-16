[CmdletBinding()]
param(
    [switch]$ConfirmInvalidation
)

$ErrorActionPreference = 'Stop'

if (-not $ConfirmInvalidation) {
    throw 'Re-run with -ConfirmInvalidation. Rotating these secrets invalidates outstanding Claim Codes/invitations and changes anonymous aliases.'
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) {
    throw 'Missing .env. Nothing was rotated.'
}

function New-Secret([int]$ByteCount = 48) {
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

$replacements = @{
    'HUMHUB_DEBUG' = 'false'
    'CLASS_ARCHIVE_CLAIM_CODE_PEPPER' = New-Secret
    'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET' = New-Secret
}

$lines = [IO.File]::ReadAllLines($envPath)
foreach ($name in $replacements.Keys) {
    $matchIndexes = @()
    for ($index = 0; $index -lt $lines.Count; $index++) {
        if ($lines[$index].StartsWith("$name=")) {
            $matchIndexes += $index
        }
    }
    if ($matchIndexes.Count -ne 1) {
        throw "Expected exactly one $name entry in .env; no changes were written."
    }
    $lines[$matchIndexes[0]] = "$name=$($replacements[$name])"
}

$temporaryPath = "$envPath.rotate.tmp"
try {
    [IO.File]::WriteAllLines($temporaryPath, $lines, [Text.UTF8Encoding]::new($false))
    Move-Item -LiteralPath $temporaryPath -Destination $envPath -Force
}
finally {
    Remove-Item -LiteralPath $temporaryPath -Force -ErrorAction SilentlyContinue
}

Write-Host 'Rotated only the local Claim/pseudonym secrets and forced HUMHUB_DEBUG=false. Database and administrator credentials were preserved.'
