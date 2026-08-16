function Enter-ClassArchivePluginWorkflowLock {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)]
        [string]$LockPath
    )

    $lockDirectory = Split-Path -Parent $LockPath
    if ([string]::IsNullOrWhiteSpace($lockDirectory)) {
        throw 'The Class Archive plugin workflow lock requires an explicit parent directory.'
    }

    New-Item -ItemType Directory -Path $lockDirectory -Force | Out-Null
    $directoryItem = Get-Item -LiteralPath $lockDirectory -Force
    if (
        -not $directoryItem.PSIsContainer `
        -or (($directoryItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)
    ) {
        throw 'Refusing an unsafe Class Archive plugin workflow lock directory.'
    }

    if (Test-Path -LiteralPath $LockPath) {
        $lockItem = Get-Item -LiteralPath $LockPath -Force
        if (
            $lockItem.PSIsContainer `
            -or (($lockItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)
        ) {
            throw 'Refusing an unsafe Class Archive plugin workflow lock file.'
        }
    }

    try {
        # The file is intentionally neither truncated nor deleted. FileShare.None
        # makes the kernel handle the mutex and releases it automatically if this
        # Windows process exits or crashes.
        $handle = [IO.File]::Open(
            $LockPath,
            [IO.FileMode]::OpenOrCreate,
            [IO.FileAccess]::ReadWrite,
            [IO.FileShare]::None
        )
    }
    catch [IO.IOException] {
        throw [InvalidOperationException]::new(
            'Refusing overlapping Class Archive plugin workflow: another Windows orchestrator owns the exclusive lock.',
            $_.Exception
        )
    }

    try {
        $lockedItem = Get-Item -LiteralPath $LockPath -Force
        if (
            $lockedItem.PSIsContainer `
            -or (($lockedItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)
        ) {
            throw 'Refusing an unsafe Class Archive plugin workflow lock after acquisition.'
        }
        return $handle
    }
    catch {
        $handle.Dispose()
        throw
    }
}

function Exit-ClassArchivePluginWorkflowLock {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $false)]
        [AllowNull()]
        [IO.FileStream]$Handle
    )

    if ($null -ne $Handle) {
        $Handle.Dispose()
    }
}
