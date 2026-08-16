[CmdletBinding()]
param(
    [ValidateSet('Test', 'Hold')]
    [string]$Mode = 'Test',

    [ValidatePattern('^[a-f0-9]{16}$')]
    [string]$RunId
)

$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$helperPath = Join-Path $projectRoot 'infra\scripts\class-plugin-workflow-lock.ps1'
$devPath = Join-Path $projectRoot 'infra\scripts\dev.ps1'
$runtimeDirectory = Join-Path $projectRoot '.codex-work\runtime'
$lockPath = Join-Path $runtimeDirectory 'class-plugin-workflow.lock'
. $helperPath

function Get-TestPath {
    param([string]$Kind)
    Join-Path $runtimeDirectory ("workflow-lock-test-{0}.{1}" -f $RunId, $Kind)
}

if ($Mode -eq 'Hold') {
    if ([string]::IsNullOrWhiteSpace($RunId)) {
        throw 'Hold mode requires a run id.'
    }

    $holder = $null
    try {
        $holder = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
        [IO.File]::WriteAllText(
            (Get-TestPath 'marker'),
            "FIRST_OWNER:$RunId",
            [Text.UTF8Encoding]::new($false)
        )
        [IO.File]::WriteAllText(
            (Get-TestPath 'ready'),
            $RunId,
            [Text.UTF8Encoding]::new($false)
        )

        # The parent deliberately terminates this process to prove that the OS
        # closes the exclusive handle without relying on a cleanup callback.
        Start-Sleep -Seconds 60
    }
    finally {
        Exit-ClassArchivePluginWorkflowLock -Handle $holder
    }
    exit 0
}

$passed = 0
$failed = 0
$holderProcess = $null
$ownedPaths = @()

function Assert-True {
    param([bool]$Condition, [string]$Label)
    if ($Condition) {
        $script:passed++
        Write-Output "PASS $Label"
    }
    else {
        $script:failed++
        Write-Output "FAIL $Label"
    }
}

if ([string]::IsNullOrWhiteSpace($RunId)) {
    $RunId = ([guid]::NewGuid().ToString('N')).Substring(0, 16)
}

New-Item -ItemType Directory -Path $runtimeDirectory -Force | Out-Null
$readyPath = Get-TestPath 'ready'
$markerPath = Get-TestPath 'marker'
$stdoutPath = Get-TestPath 'stdout'
$stderrPath = Get-TestPath 'stderr'
$unknownLockPath = Get-TestPath 'unknown-lock'
$ownedPaths = @($readyPath, $markerPath, $stdoutPath, $stderrPath, $unknownLockPath)
$lockExistedBefore = Test-Path -LiteralPath $lockPath
[byte[]]$lockBytesBefore = @()
if ($lockExistedBefore) {
    $lockBytesBefore = [IO.File]::ReadAllBytes($lockPath)
}

try {
    $holderProcess = Start-Process -FilePath 'powershell.exe' `
        -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $PSCommandPath,
            '-Mode', 'Hold', '-RunId', $RunId
        ) `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath

    $ready = $false
    for ($attempt = 1; $attempt -le 100; $attempt++) {
        if (Test-Path -LiteralPath $readyPath) {
            $ready = ([IO.File]::ReadAllText($readyPath) -eq $RunId)
            break
        }
        if ($holderProcess.HasExited) {
            break
        }
        Start-Sleep -Milliseconds 50
        $holderProcess.Refresh()
    }
    Assert-True $ready 'first workflow acquired the exclusive Windows lock'
    if (-not $ready) {
        $workerError = if (Test-Path -LiteralPath $stderrPath) { [IO.File]::ReadAllText($stderrPath) } else { '' }
        throw "The lock holder did not become ready. $workerError"
    }

    $markerBefore = [IO.File]::ReadAllText($markerPath)
    $previousErrorPreference = $ErrorActionPreference
    $rejectionTimer = [Diagnostics.Stopwatch]::StartNew()
    try {
        # The expected native stderr rejection must be captured as evidence,
        # not promoted into a terminating PowerShell error by this test's
        # fail-fast preference.
        $ErrorActionPreference = 'Continue'
        $secondOutput = @(& powershell.exe -NoProfile -ExecutionPolicy Bypass -File $devPath class-plugins 2>&1)
        $secondExit = $LASTEXITCODE
    }
    finally {
        $rejectionTimer.Stop()
        $ErrorActionPreference = $previousErrorPreference
    }
    $secondText = $secondOutput -join "`n"

    Assert-True ($secondExit -eq 1) 'concurrent dev.ps1 class-plugins was rejected'
    Assert-True (
        $secondText.Contains('Refusing overlapping Class Archive plugin workflow')
    ) 'concurrent rejection was explicit'
    Assert-True ($rejectionTimer.Elapsed.TotalSeconds -lt 5) 'concurrent rejection was non-blocking'
    Assert-True (
        [IO.File]::ReadAllText($markerPath) -eq $markerBefore
    ) 'second workflow did not alter the first owner marker semantics'
    Assert-True (-not $holderProcess.HasExited) 'first workflow retained ownership after rejection'

    # A forced process exit bypasses the holder's finally block. The subsequent
    # acquisition therefore proves kernel-handle recovery, not cooperative cleanup.
    Stop-Process -Id $holderProcess.Id -Force
    $holderProcess.WaitForExit()
    $holderProcess = $null

    $recoveredHandle = $null
    try {
        $recoveredHandle = Enter-ClassArchivePluginWorkflowLock -LockPath $lockPath
        Assert-True ($null -ne $recoveredHandle) 'OS released the lock after a crashed owner'
    }
    finally {
        Exit-ClassArchivePluginWorkflowLock -Handle $recoveredHandle
    }

    [byte[]]$lockBytesAfter = [IO.File]::ReadAllBytes($lockPath)
    Assert-True (
        [Convert]::ToBase64String($lockBytesAfter) -eq [Convert]::ToBase64String($lockBytesBefore)
    ) 'lock acquisition preserved pre-existing lock-file bytes'

    $unknownBytes = [Text.UTF8Encoding]::new($false).GetBytes("UNKNOWN-LOCK-CONTENT:$RunId")
    [IO.File]::WriteAllBytes($unknownLockPath, $unknownBytes)
    $unknownHandle = $null
    try {
        $unknownHandle = Enter-ClassArchivePluginWorkflowLock -LockPath $unknownLockPath
    }
    finally {
        Exit-ClassArchivePluginWorkflowLock -Handle $unknownHandle
    }
    Assert-True (
        [Convert]::ToBase64String([IO.File]::ReadAllBytes($unknownLockPath)) -eq `
            [Convert]::ToBase64String($unknownBytes)
    ) 'helper did not truncate or delete an unknown persistent lock file'

    $devSource = [IO.File]::ReadAllText($devPath)
    Assert-True (
        ([regex]::Matches($devSource, '\$classPluginWorkflow\s*=\s*\$true')).Count -eq 3
    ) 'class-plugins and both identity aliases share the workflow gate'
    $tailStart = $devSource.IndexOf('$classPluginWorkflowLock = $null', [StringComparison]::Ordinal)
    $lockAcquire = $devSource.IndexOf('Enter-ClassArchivePluginWorkflowLock', $tailStart, [StringComparison]::Ordinal)
    $prepare = $devSource.IndexOf('Invoke-ClassArchiveMaintenancePrepare', $lockAcquire, [StringComparison]::Ordinal)
    Assert-True (
        $tailStart -ge 0 -and $lockAcquire -gt $tailStart -and $prepare -gt $lockAcquire
    ) 'main orchestrator acquires the lock before maintenance preparation'
    Assert-True (
        $devSource.IndexOf('finally {', $prepare, [StringComparison]::Ordinal) -gt $prepare `
        -and $devSource.IndexOf('Exit-ClassArchivePluginWorkflowLock', $prepare, [StringComparison]::Ordinal) -gt $prepare
    ) 'main orchestrator releases its handle from finally'
}
finally {
    if ($null -ne $holderProcess -and -not $holderProcess.HasExited) {
        Stop-Process -Id $holderProcess.Id -Force -ErrorAction SilentlyContinue
        $holderProcess.WaitForExit()
    }
    foreach ($ownedPath in $ownedPaths) {
        if (Test-Path -LiteralPath $ownedPath) {
            Remove-Item -LiteralPath $ownedPath -Force
        }
    }
}

Write-Output "Class plugin workflow lock: $passed passed, $failed failed"
if ($failed -ne 0) {
    exit 1
}
exit 0
