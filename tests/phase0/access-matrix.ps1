[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'

function Assert-True([bool]$Condition, [string]$Message) {
    if (-not $Condition) { throw $Message }
}

function Read-Port([string]$Path) {
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        if ($line.StartsWith('CLASS_ARCHIVE_HTTP_PORT=')) {
            return $line.Substring('CLASS_ARCHIVE_HTTP_PORT='.Length)
        }
    }
    throw 'CLASS_ARCHIVE_HTTP_PORT is missing.'
}

function New-TransientPassword {
    $bytes = New-Object byte[] 32
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Invoke-WS {
    param(
        [Parameter(Mandatory = $true)][Uri]$Uri,
        [Parameter(Mandatory = $true)][Microsoft.PowerShell.Commands.WebRequestSession]$Session,
        [Parameter(Mandatory = $true)][hashtable]$Body
    )
    return Invoke-RestMethod -Uri $Uri -Method Post -Body $Body -WebSession $Session -TimeoutSec 30
}

function Login-Role {
    param([Uri]$Uri, [string]$Username, [string]$Password)
    $session = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    $response = Invoke-WS -Uri $Uri -Session $session -Body @{
        method = 'pwg.session.login'
        username = $Username
        password = $Password
    }
    Assert-True ($response.stat -eq 'ok' -and $response.result) "Login failed for $Username."
    return $session
}

function Flatten-Categories($Rows) {
    $output = @()
    foreach ($row in @($Rows)) {
        if ($null -eq $row) { continue }
        $output += $row
        foreach ($property in @('categories', 'sub_categories', 'children')) {
            if ($null -ne $row.$property) {
                $output += Flatten-Categories $row.$property
            }
        }
    }
    return $output
}

if (-not (Test-Path -LiteralPath $envPath)) { throw 'Missing ignored .env.piwigo.' }
$port = Read-Port $envPath
$wsUri = [Uri]("http://127.0.0.1:$port/ws.php?format=json")
$fixturePassword = New-TransientPassword

$compose = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml',
    'exec', '-T', '--user', 'nginx', '-e', "CLASS_ARCHIVE_FIXTURE_PASSWORD=$fixturePassword",
    'piwigo', 'php', '/workspace/tests/fixtures/provision-access-users.php'
)
$provisionOutput = & wsl.exe @compose
if ($LASTEXITCODE -ne 0 -or 'ACCESS_FIXTURES_READY' -notin @($provisionOutput)) {
    throw 'Access fixture provisioning failed.'
}

$guestDenied = $false
try {
    $guestSession = [Microsoft.PowerShell.Commands.WebRequestSession]::new()
    Invoke-WS -Uri $wsUri -Session $guestSession -Body @{ method = 'pwg.categories.getList'; recursive = 'true' } | Out-Null
}
catch {
    $guestDenied = [int]$_.Exception.Response.StatusCode -eq 401
}
Assert-True $guestDenied 'Guest Web API access was not denied.'

$expected = @{
    'fixture-classmate' = @{ Heritage = $true; Living = $true }
    'fixture-teacher' = @{ Heritage = $true; Living = $true }
    'fixture-family' = @{ Heritage = $true; Living = $false }
    'fixture-anonymous' = @{ Heritage = $true; Living = $true }
}
$sessions = @{}
$categoryRows = @{}
foreach ($username in $expected.Keys) {
    $sessions[$username] = Login-Role -Uri $wsUri -Username $username -Password $fixturePassword
    $response = Invoke-WS -Uri $wsUri -Session $sessions[$username] -Body @{
        method = 'pwg.categories.getList'
        faked_by_community = 'false'
        recursive = 'true'
    }
    Assert-True ($response.stat -eq 'ok') "Category enumeration failed for $username."
    $rows = if ($null -ne $response.result.categories) { $response.result.categories } else { $response.result }
    $categoryRows[$username] = @(Flatten-Categories $rows)
    $permalinks = @($categoryRows[$username] | ForEach-Object { $_.permalink })
    Assert-True (($permalinks -contains 'class-archive-heritage') -eq $expected[$username].Heritage) "HERITAGE visibility mismatch for $username."
    Assert-True (($permalinks -contains 'class-archive-living') -eq $expected[$username].Living) "LIVING visibility mismatch for $username."
}

$living = $categoryRows['fixture-classmate'] | Where-Object { $_.permalink -eq 'class-archive-living' } | Select-Object -First 1
Assert-True ($null -ne $living) 'Could not resolve the LIVING album id.'
$familyDirect = Invoke-WS -Uri $wsUri -Session $sessions['fixture-family'] -Body @{
    method = 'pwg.categories.getImages'
    cat_id = [string]$living.id
    recursive = 'true'
    per_page = '100'
}
$familyImages = if ($null -ne $familyDirect.result.images) { @($familyDirect.result.images) } else { @() }
Assert-True ($familyDirect.stat -ne 'ok' -or $familyImages.Count -eq 0) 'Family retrieved LIVING images by direct category id.'

Write-Output 'ACCESS_MATRIX_ASSERTIONS=PASS'
Write-Output 'GUEST_ALBUM_API_DENIED=PASS'
Write-Output 'FAMILY_HERITAGE_ONLY=PASS'
Write-Output 'CLASSMATE_TEACHER_ANONYMOUS_BOTH_ERAS=PASS'
