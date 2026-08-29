[CmdletBinding()]
param(
    [string]$PullRequestBase = '',
    [string]$PushBase = '',
    [Parameter(Mandatory = $true)]
    [string]$DefaultBranch,
    [string]$RepositoryRoot = ''
)

# Resolve the history base used by public-boundary and credential scans.  A
# newly-created branch has an all-zero push ``before`` value, so its fallback
# must be the merge-base with the repository's validated default branch.  The
# resolver emits only a commit hash on success and one safe reason on failure.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Stop-Resolver([string]$Reason) {
    if ($Reason -notmatch '^[a-z0-9_]{1,64}$') { $Reason = 'unexpected' }
    Write-Output "PUBLIC_BOUNDARY_BASE=FAIL reason=$Reason"
    exit 1
}

function Invoke-Git([string[]]$Arguments) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& git -C $script:repositoryRoot @Arguments 2>$null)
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previous
    }
    return [pscustomobject]@{ ExitCode = $code; Output = $output }
}

function Resolve-Commit([string]$Reference, [string]$FailureReason) {
    $result = Invoke-Git @('rev-parse', '--verify', ($Reference + '^{commit}'))
    if (
        $result.ExitCode -ne 0 -or
        $result.Output.Count -ne 1 -or
        [string]$result.Output[0] -notmatch '^[0-9a-f]{40,64}$'
    ) {
        throw $FailureReason
    }
    return [string]$result.Output[0]
}

try {
    if ([string]::IsNullOrWhiteSpace($RepositoryRoot)) {
        $RepositoryRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
    }
    $script:repositoryRoot = [IO.Path]::GetFullPath($RepositoryRoot)
    $top = Invoke-Git @('rev-parse', '--show-toplevel')
    if ($top.ExitCode -ne 0 -or $top.Output.Count -ne 1) { throw 'repository_root_invalid' }
    $resolvedTop = [IO.Path]::GetFullPath([string]$top.Output[0]).TrimEnd([char[]]@([char]92, [char]47))
    $resolvedRoot = $script:repositoryRoot.TrimEnd([char[]]@([char]92, [char]47))
    if (![StringComparer]::OrdinalIgnoreCase.Equals($resolvedTop, $resolvedRoot)) {
        throw 'repository_root_invalid'
    }

    $pullBase = $PullRequestBase.Trim()
    $pushBase = $PushBase.Trim()
    if ($PullRequestBase -cne $pullBase) { throw 'pull_request_base_invalid' }
    if ($PushBase -cne $pushBase) { throw 'push_base_invalid' }
    if ($pullBase -ne '') {
        if (
            $pullBase -notmatch '^[0-9a-fA-F]{40,64}$' -or
            $pullBase -match '^0+$'
        ) {
            throw 'pull_request_base_invalid'
        }
        $base = Resolve-Commit $pullBase 'event_base_unavailable'
    } elseif ($pushBase -ne '' -and $pushBase -notmatch '^0+$') {
        if ($pushBase -notmatch '^[0-9a-fA-F]{40,64}$') {
            throw 'push_base_invalid'
        }
        $base = Resolve-Commit $pushBase 'event_base_unavailable'
    } else {
        if (
            [string]::IsNullOrWhiteSpace($DefaultBranch) -or
            $DefaultBranch -ne $DefaultBranch.Trim() -or
            $DefaultBranch -notmatch '^[A-Za-z0-9][A-Za-z0-9._/-]{0,254}$'
        ) {
            throw 'default_branch_invalid'
        }
        $refCheck = Invoke-Git @('check-ref-format', '--branch', $DefaultBranch)
        if ($refCheck.ExitCode -ne 0) { throw 'default_branch_invalid' }

        $defaultRef = 'refs/remotes/origin/' + $DefaultBranch
        $defaultCommit = Resolve-Commit $defaultRef 'default_branch_commit_unavailable'
        $mergeBase = Invoke-Git @('merge-base', 'HEAD', $defaultCommit)
        if (
            $mergeBase.ExitCode -ne 0 -or
            $mergeBase.Output.Count -ne 1 -or
            [string]$mergeBase.Output[0] -notmatch '^[0-9a-f]{40,64}$'
        ) {
            throw 'default_branch_merge_base_unavailable'
        }
        $base = [string]$mergeBase.Output[0]
    }

    if ($base -notmatch '^[0-9a-f]{40,64}$') { throw 'resolved_base_invalid' }
    Write-Output $base
} catch {
    Stop-Resolver ([string]$_.Exception.Message)
}
