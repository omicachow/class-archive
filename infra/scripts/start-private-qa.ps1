[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('status', 'start', 'credentials')]
    [string]$Action = 'status'
)

# Human-facing local launcher for the two deliberately different photo
# environments.  It never reads or prints a password and delegates all runtime
# and credential work to the existing fail-closed owners.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRunner = Join-Path $PSScriptRoot 'private-qa.ps1'
$credentialRunner = Join-Path $projectRoot 'tests\phase3\private-browser-fixture.ps1'

function Invoke-Checked([string]$Script, [string[]]$Arguments) {
    $lines = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $Script @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) {
        throw 'class_archive_local_qa_command_failed'
    }
    return @($lines | ForEach-Object { [string]$_ })
}

if ($Action -eq 'start') {
    [void](Invoke-Checked $privateRunner @('up'))
} else {
    [void](Invoke-Checked $privateRunner @('validate'))
}

Write-Output 'PUBLIC_SAFE_SYNTHETIC_UI=http://127.0.0.1:8091/photos'
Write-Output 'PRIVATE_REAL_QA_UI=http://127.0.0.1:8191/photos'
Write-Output 'PRIVATE_REAL_QA_SCOPE=LOCALHOST_ONLY'

if ($Action -eq 'credentials') {
    $result = Invoke-Checked $credentialRunner @('prepare', '-Environment', 'private')
    $credential = @($result | Where-Object { $_ -match '^PRIVATE_BROWSER_FIXTURE=PASS action=prepare credential=' })
    if ($credential.Count -ne 1) {
        throw 'class_archive_local_qa_credential_prepare_failed'
    }
    # The file is ignored and owner-only.  Keep the secret out of terminal
    # output while still giving the operator a precise local retrieval path.
    $path = $credential[0].Substring($credential[0].IndexOf('credential=') + 11)
    Write-Output ('PRIVATE_QA_CREDENTIAL_FILE=' + $path)
    Write-Output 'PRIVATE_QA_CREDENTIALS=OWNER_ONLY_FILE_NOT_PRINTED'
}
