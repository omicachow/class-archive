[CmdletBinding()]
param(
    # ENSURE is deliberately a separate, pre-snapshot bootstrap action. It is
    # never called from the Chrome lease wrapper and never emits a credential.
    [switch]$ConfirmTeacherFixtureEnsure
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $ConfirmTeacherFixtureEnsure) {
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_ENSURE=BLOCKED code=explicit_teacher_fixture_ensure_confirmation_required'
    exit 3
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$fixtureRun = '3e2f1a94b0c74d81952e6f0a'
$fixtureTarget = 'PRIVATE_REAL_FULL_OWNER'
$fixtureAck = 'LEASED_TEACHER_FIXTURE_V1'
$envRelative = 'infra/private-full/.env.piwigo.owner'
$composeProject = 'class_archive_private_full_v3_piwigo'
$composeFiles = @('infra/docker-compose.yml', 'infra/private-full/docker-compose.override.yml')
$brokerPath = '/workspace/tests/phase3/photos-app-v4-owner-teacher-fixture-broker.php'

. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerTeacherEnsure([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_TEACHER_ENSURE_STOP:' + $Code)
}

function Get-OwnerComposeArguments([string[]]$Tail) {
    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', $envRelative)) {
        $arguments.Add($argument)
    }
    foreach ($file in $composeFiles) { $arguments.Add('-f'); $arguments.Add($file) }
    $arguments.Add('-p'); $arguments.Add($composeProject)
    foreach ($argument in $Tail) { $arguments.Add($argument) }
    return [string[]]$arguments.ToArray()
}

function Invoke-BoundedTeacherEnsure([string[]]$Tail, [int]$TimeoutSeconds) {
    $wslArguments = Add-ClassArchiveWslTimeout -Arguments (Get-OwnerComposeArguments $Tail) -TimeoutSeconds $TimeoutSeconds
    return Invoke-ClassArchiveBoundedNative `
        -Executable "$env:SystemRoot\System32\wsl.exe" `
        -Arguments $wslArguments `
        -TimeoutSeconds ($TimeoutSeconds + 15) `
        -WorkingDirectory $projectRoot
}

try {
    # ENSURE is the broker's only creation/Claim path. The broker starts by
    # rejecting an extant recovery ledger, then either proves the existing
    # fixture's frozen descriptor or creates/claims and immediately freezes it.
    # This script never invokes LEASE, never opens a browser, and does not
    # accept or request any credential-bearing output.
    $result = Invoke-BoundedTeacherEnsure -Tail @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=' + $fixtureTarget),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' + $fixtureAck),
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ENSURE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + $fixtureRun),
        'piwigo', 'php', $brokerPath, $fixtureRun
    ) -TimeoutSeconds 180
    $lines = @(([string]$result.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
    $expected = 'V4_OWNER_TEACHER_FIXTURE=ENSURED identity=FROZEN credentials=unknown sessions=revoked'
    if ($result.TimedOut -or $result.ExitCode -ne 0 -or $lines.Count -ne 1 -or $lines[0] -ne $expected) {
        Stop-V4OwnerTeacherEnsure 'ensure_broker_failed'
    }
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_ENSURE=PASS identity=FROZEN credentials=unknown sessions=revoked target=PRIVATE_REAL_FULL_OWNER'
}
catch {
    # Broker stderr and any unexpected output may contain private runtime
    # diagnostics, so the public terminal receives only a bounded code.
    $code = if ($_.Exception.Message -match '^V4_OWNER_TEACHER_ENSURE_STOP:([A-Za-z0-9_]{1,120})$') { [string]$Matches[1] } else { 'unexpected' }
    Write-Output ('V4_OWNER_TEACHER_FIXTURE_ENSURE=FAIL stage=ensure code=' + $code.ToLowerInvariant())
    exit 2
}
