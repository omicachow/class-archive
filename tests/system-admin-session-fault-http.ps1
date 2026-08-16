[CmdletBinding()]
param()

# Real, isolated regression for the native-process ambiguity window after the
# synthetic SYSTEM_ADMIN session UPDATE commits but before the fixture emits
# its success JSON. No password or bearer session value is logged or persisted.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'tests\support\system-admin-session.ps1')

function Read-UniqueSetting {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Key
    )

    $matches = @()
    foreach ($line in [IO.File]::ReadAllLines($Path)) {
        $trimmed = $line.Trim()
        if ($trimmed.Length -eq 0 -or $trimmed.StartsWith('#')) { continue }
        $separator = $trimmed.IndexOf('=')
        if ($separator -lt 1) { throw 'Invalid .env.piwigo syntax.' }
        if ($trimmed.Substring(0, $separator) -eq $Key) {
            $matches += $trimmed.Substring($separator + 1)
        }
    }
    if ($matches.Count -ne 1 -or [string]::IsNullOrWhiteSpace([string]$matches[0])) {
        throw "Expected one non-empty ignored local setting: $Key."
    }
    return [string]$matches[0]
}

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'Missing ignored .env.piwigo.'
}
Assert-ClassArchiveOwnerOnlyFileAcl -Path $envPath
if ([IO.File]::ReadAllText($envPath) -match '(?m)^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=') {
    throw 'Refusing a long-lived plaintext administrator password.'
}

$port = Read-UniqueSetting -Path $envPath -Key 'CLASS_ARCHIVE_HTTP_PORT'
$adminUsername = Read-UniqueSetting -Path $envPath -Key 'PIWIGO_ADMIN_USERNAME'
if ($port -notmatch '^[0-9]{1,5}$' -or [int]$port -lt 1 -or [int]$port -gt 65535) {
    throw 'Invalid localhost HTTP port.'
}

$baseUri = [Uri]("http://127.0.0.1:$port/")
$composeBase = @(
    '-d', 'Ubuntu', '--cd', $projectRoot, '--',
    'docker', 'compose', '--env-file', '.env.piwigo', '-f', 'infra/docker-compose.yml'
)

$before = Get-ClassArchiveSystemAdminSessionFixtureState `
    -ComposeBase $composeBase -AdminUsername $adminUsername
if ($before.LeaseCount -ne 0 -or $before.AdminSessionCount -ne 0) {
    throw 'The isolated fault regression requires zero pre-existing fixture leases and SYSTEM_ADMIN sessions.'
}

$unexpectedLease = $null
$faultObserved = $false
try {
    $unexpectedLease = New-ClassArchiveSystemAdminSession -BaseUri $baseUri `
        -ComposeBase $composeBase -AdminUsername $adminUsername `
        -FaultInjection 'after_db_commit_before_json'
}
catch {
    if ($_.Exception.Message -ne 'Observed injected SYSTEM_ADMIN session failure after the database transition committed.') {
        throw
    }
    $faultObserved = $true
}
finally {
    if ($null -ne $unexpectedLease) {
        Remove-ClassArchiveSystemAdminSession -Lease $unexpectedLease
    }
}

if (-not $faultObserved) {
    throw 'The after-commit fault injection was not observed.'
}
$after = Get-ClassArchiveSystemAdminSessionFixtureState `
    -ComposeBase $composeBase -AdminUsername $adminUsername
if ($after.LeaseCount -ne 0 -or $after.AdminSessionCount -ne 0) {
    throw 'Exact cleanup did not remove both the faulted lease and its SYSTEM_ADMIN-bound session.'
}

Write-Output 'SYSTEM_ADMIN_SESSION_FAULT_HTTP=PASS fault=after_db_commit_before_json leases=0 admin_sessions=0'
