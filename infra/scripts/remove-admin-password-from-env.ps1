[CmdletBinding(SupportsShouldProcess = $true, ConfirmImpact = 'High')]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$envPath = Join-Path $projectRoot '.env.piwigo'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

if (-not (Test-Path -LiteralPath $envPath -PathType Leaf)) {
    throw 'Missing ignored .env.piwigo.'
}
if (-not $PSCmdlet.ShouldProcess($envPath, 'permanently remove PIWIGO_ADMIN_PASSWORD and restrict the file ACL')) {
    return
}

$lines = [IO.File]::ReadAllLines($envPath)
$matches = @($lines | Where-Object { $_ -match '^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=' })
if ($matches.Count -gt 1) {
    throw 'Refusing an ambiguous .env.piwigo with duplicate PIWIGO_ADMIN_PASSWORD entries.'
}
if ($matches.Count -eq 0) {
    Set-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    Write-Host 'No plaintext administrator entry remains; .env.piwigo ACL is restricted.'
    return
}
$retained = @($lines | Where-Object { $_ -notmatch '^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=' })
$temporaryPath = Join-Path (Split-Path -Parent $envPath) ('.env.piwigo.rewrite.' + [Guid]::NewGuid().ToString('N'))
$backupPath = Join-Path (Split-Path -Parent $envPath) ('.env.piwigo.legacy-backup.' + [Guid]::NewGuid().ToString('N'))

function Remove-ExactMigrationArtifact {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Label
    )

    if (Test-Path -LiteralPath $Path -ErrorAction Stop) {
        Remove-Item -LiteralPath $Path -Force -ErrorAction Stop
    }
    if (Test-Path -LiteralPath $Path -ErrorAction Stop) {
        throw "The $Label migration artifact still exists after deletion."
    }
}

try {
    # Restrict the legacy file before File.Replace can materialize its backup.
    # Both temporary artifacts are exact, same-directory paths and are removed
    # in finally even if a later verification step fails.
    Set-ClassArchiveOwnerOnlyFileAcl -Path $envPath
    [IO.File]::WriteAllLines($temporaryPath, $retained, [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $temporaryPath
    [IO.File]::Replace($temporaryPath, $envPath, $backupPath, $true)
    Set-ClassArchiveOwnerOnlyFileAcl -Path $envPath
}
finally {
    # A cleanup failure is security-critical because either artifact may hold
    # the legacy plaintext. Attempt both exact paths, then fail closed if either
    # absence could not be proven.
    $cleanupFailures = [Collections.Generic.List[string]]::new()
    foreach ($artifact in @(
        @{ Path = $temporaryPath; Label = 'rewrite temporary' },
        @{ Path = $backupPath; Label = 'legacy backup' }
    )) {
        try {
            Remove-ExactMigrationArtifact -Path ([string]$artifact.Path) -Label ([string]$artifact.Label)
        }
        catch {
            $cleanupFailures.Add([string]$artifact.Label)
        }
    }
    if ($cleanupFailures.Count -gt 0) {
        throw ('Credential migration cleanup could not prove deletion of: ' + ($cleanupFailures -join ', ') + '.')
    }
}

if ([IO.File]::ReadAllText($envPath) -match '(?m)^[ \t]*PIWIGO_ADMIN_PASSWORD[ \t]*=') {
    throw 'The plaintext administrator entry remains after the rewrite.'
}
Write-Host 'Removed the plaintext administrator entry and restricted .env.piwigo to owner, SYSTEM, and Administrators.'
