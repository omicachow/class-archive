Set-StrictMode -Version Latest

# A deliberately small host-side lease for Synthetic 8091 V4 acceptance
# workflows. Container-local fixture locks remain authoritative for their
# precise mutations; this marker serializes the full host lifecycle between
# preflight/baseline, fixture work, browser evidence, and cleanup.
#
# The lease is intentionally fail-closed. An existing marker is never removed
# automatically: it may represent a still-running workflow, a crashed process,
# or an ambiguous filesystem state. The owning wrapper must remove its exact
# random token in finally before it emits an attester-eligible PASS record.

function Assert-V4SyntheticPhaseALeaseChildPath {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$Base,
        [Parameter(Mandatory = $true)][string]$Target,
        [Parameter(Mandatory = $true)][string]$Code
    )

    $separator = [IO.Path]::DirectorySeparatorChar
    $relative = [IO.Path]::GetRelativePath($Base, $Target)
    if (
        [string]::IsNullOrWhiteSpace($relative) -or
        $relative -eq '..' -or
        $relative.StartsWith('..' + $separator, [StringComparison]::Ordinal) -or
        [IO.Path]::IsPathRooted($relative)
    ) {
        throw $Code
    }
}

function Get-V4SyntheticPhaseALeaseLocation {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    $root = (Resolve-Path -LiteralPath $ProjectRoot).Path
    $workRoot = [IO.Path]::GetFullPath((Join-Path $root '.codex-work'))
    $runtimeRoot = [IO.Path]::GetFullPath((Join-Path $workRoot 'runtime'))
    $leaseRoot = [IO.Path]::GetFullPath((Join-Path $runtimeRoot 'v4-synthetic-phase-a'))
    $leasePath = [IO.Path]::GetFullPath((Join-Path $leaseRoot 'mutation.lock'))
    foreach ($path in @($workRoot, $runtimeRoot, $leaseRoot, $leasePath)) {
        Assert-V4SyntheticPhaseALeaseChildPath -Base $root -Target $path -Code 'v4_synthetic_phase_a_lease_path_outside_work_root'
    }
    return [ordered]@{
        project_root = $root
        work_root = $workRoot
        runtime_root = $runtimeRoot
        lease_root = $leaseRoot
        lease_path = $leasePath
    }
}

function Assert-V4SyntheticPhaseALeaseIgnoredUntracked {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$Code
    )

    Assert-V4SyntheticPhaseALeaseChildPath -Base $ProjectRoot -Target $Path -Code $Code
    $relative = $Path.Substring($ProjectRoot.Length + 1).Replace('\', '/')
    & git -C $ProjectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $ProjectRoot ls-files -- $relative).Count -ne 0) {
        throw $Code
    }
}

function Import-V4SyntheticPhaseALeaseAclSupport {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][string]$ProjectRoot)

    if ($null -eq (Get-Command Assert-ClassArchiveOwnerOnlyFileAcl -ErrorAction SilentlyContinue)) {
        $aclScript = Join-Path $ProjectRoot 'infra\scripts\secret-file-acl.ps1'
        if (-not (Test-Path -LiteralPath $aclScript -PathType Leaf)) {
            throw 'v4_synthetic_phase_a_lease_acl_support_missing'
        }
        . $aclScript
    }
}

function Initialize-V4SyntheticPhaseALeaseRoot {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Location)

    Import-V4SyntheticPhaseALeaseAclSupport -ProjectRoot $Location.project_root
    foreach ($path in @($Location.work_root, $Location.runtime_root, $Location.lease_root, $Location.lease_path)) {
        Assert-V4SyntheticPhaseALeaseIgnoredUntracked -ProjectRoot $Location.project_root -Path $path -Code 'v4_synthetic_phase_a_lease_not_ignored'
    }
    [void][IO.Directory]::CreateDirectory($Location.lease_root)
    foreach ($path in @($Location.work_root, $Location.runtime_root, $Location.lease_root)) {
        $item = Get-Item -LiteralPath $path -Force
        if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
            throw 'v4_synthetic_phase_a_lease_directory_unsafe'
        }
    }
}

function Assert-V4SyntheticPhaseALeaseLeaf {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Location)

    if (-not (Test-Path -LiteralPath $Location.lease_path -PathType Leaf)) {
        throw 'v4_synthetic_phase_a_lease_missing'
    }
    $item = Get-Item -LiteralPath $Location.lease_path -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) {
        throw 'v4_synthetic_phase_a_lease_leaf_unsafe'
    }
    Assert-V4SyntheticPhaseALeaseIgnoredUntracked -ProjectRoot $Location.project_root -Path $Location.lease_path -Code 'v4_synthetic_phase_a_lease_not_ignored'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Location.lease_path
}

function New-V4SyntheticPhaseALeaseToken {
    [CmdletBinding()]
    param()

    $bytes = New-Object byte[] 32
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Read-V4SyntheticPhaseALeaseRecord {
    [CmdletBinding()]
    param([Parameter(Mandatory = $true)][System.Collections.IDictionary]$Location)

    Assert-V4SyntheticPhaseALeaseLeaf -Location $Location
    $item = Get-Item -LiteralPath $Location.lease_path -Force
    if ($item.Length -lt 64 -or $item.Length -gt 1024) {
        throw 'v4_synthetic_phase_a_lease_record_size_invalid'
    }
    try {
        $record = [IO.File]::ReadAllText($Location.lease_path, [Text.UTF8Encoding]::new($false, $true)) | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw 'v4_synthetic_phase_a_lease_record_invalid'
    }
    if (
        $record.version -ne 1 -or
        [string]$record.token -notmatch '^[a-f0-9]{64}$' -or
        [string]$record.purpose -notmatch '^[a-z][a-z0-9-]{2,63}$' -or
        [int]$record.process_id -lt 1 -or
        [string]$record.process_started_at -notmatch '^\d{4}-\d{2}-\d{2}T'
    ) {
        throw 'v4_synthetic_phase_a_lease_record_shape_invalid'
    }
    return $record
}

function Enter-V4SyntheticPhaseAMutationLease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][ValidatePattern('^[a-z][a-z0-9-]{2,63}$')][string]$Purpose
    )

    $location = Get-V4SyntheticPhaseALeaseLocation -ProjectRoot $ProjectRoot
    Initialize-V4SyntheticPhaseALeaseRoot -Location $location
    if (Test-Path -LiteralPath $location.lease_path) {
        # Verify the existing leaf before refusing it. A reparse point, bad ACL,
        # malformed record, or stale marker is never removed automatically.
        try { [void](Read-V4SyntheticPhaseALeaseRecord -Location $location) }
        catch { throw 'v4_synthetic_phase_a_lease_present_or_ambiguous' }
        throw 'v4_synthetic_phase_a_lease_present_or_stale'
    }

    $token = New-V4SyntheticPhaseALeaseToken
    $process = Get-Process -Id $PID -ErrorAction Stop
    $record = [ordered]@{
        version = 1
        token = $token
        purpose = $Purpose
        process_id = $PID
        process_started_at = $process.StartTime.ToUniversalTime().ToString('o')
    }
    $stream = $null
    try {
        # CreateNew closes the check/create race. The marker remains until this
        # exact owner removes it in finally; a crash intentionally blocks a
        # later mutation rather than guessing whether prior cleanup finished.
        $stream = [IO.File]::Open(
            $location.lease_path,
            [IO.FileMode]::CreateNew,
            [IO.FileAccess]::Write,
            [IO.FileShare]::None
        )
        $bytes = [Text.UTF8Encoding]::new($false).GetBytes(($record | ConvertTo-Json -Compress -Depth 3))
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush($true)
    }
    catch [IO.IOException] {
        throw 'v4_synthetic_phase_a_lease_present_or_stale'
    }
    catch {
        throw 'v4_synthetic_phase_a_lease_initialization_failed'
    }
    finally {
        if ($null -ne $stream) { $stream.Dispose() }
    }

    try {
        Set-ClassArchiveOwnerOnlyFileAcl -Path $location.lease_path
        [void](Read-V4SyntheticPhaseALeaseRecord -Location $location)
    }
    catch {
        # Do not delete a marker whose ACL or record cannot be re-proven.
        throw 'v4_synthetic_phase_a_lease_initialization_ambiguous'
    }
    return [pscustomobject]@{
        version = 1
        project_root = $location.project_root
        lease_path = $location.lease_path
        token = $token
        purpose = $Purpose
    }
}

function Assert-V4SyntheticPhaseAExternalLease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$ProjectRoot,
        [Parameter(Mandatory = $true)][ValidatePattern('^[a-f0-9]{64}$')][string]$Token,
        [Parameter(Mandatory = $true)][ValidatePattern('^[a-z][a-z0-9-]{2,63}$')][string]$ExpectedPurpose
    )

    $location = Get-V4SyntheticPhaseALeaseLocation -ProjectRoot $ProjectRoot
    Initialize-V4SyntheticPhaseALeaseRoot -Location $location
    $record = Read-V4SyntheticPhaseALeaseRecord -Location $location
    if ([string]$record.token -cne $Token -or [string]$record.purpose -cne $ExpectedPurpose) {
        throw 'v4_synthetic_phase_a_external_lease_token_or_purpose_invalid'
    }
    try {
        $owner = Get-Process -Id ([int]$record.process_id) -ErrorAction Stop
        $started = $owner.StartTime.ToUniversalTime().ToString('o')
    }
    catch {
        throw 'v4_synthetic_phase_a_external_lease_stale'
    }
    if ($started -cne [string]$record.process_started_at) {
        throw 'v4_synthetic_phase_a_external_lease_stale'
    }
    return [pscustomobject]@{
        version = 1
        project_root = $location.project_root
        lease_path = $location.lease_path
        token = $Token
        purpose = $ExpectedPurpose
        external = $true
    }
}

function Exit-V4SyntheticPhaseAMutationLease {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $false)][AllowNull()][object]$Lease
    )

    if ($null -eq $Lease) { return }
    if (
        $Lease.version -ne 1 -or
        [string]$Lease.project_root -eq '' -or
        [string]$Lease.lease_path -eq '' -or
        [string]$Lease.token -notmatch '^[a-f0-9]{64}$' -or
        [string]$Lease.purpose -notmatch '^[a-z][a-z0-9-]{2,63}$' -or
        $Lease.PSObject.Properties['external'] -and [bool]$Lease.external
    ) {
        throw 'v4_synthetic_phase_a_lease_handle_invalid'
    }
    $location = Get-V4SyntheticPhaseALeaseLocation -ProjectRoot ([string]$Lease.project_root)
    if ([string]$location.lease_path -cne [string]$Lease.lease_path) {
        throw 'v4_synthetic_phase_a_lease_handle_path_invalid'
    }
    Initialize-V4SyntheticPhaseALeaseRoot -Location $location
    $record = Read-V4SyntheticPhaseALeaseRecord -Location $location
    if ([string]$record.token -cne [string]$Lease.token -or [string]$record.purpose -cne [string]$Lease.purpose) {
        throw 'v4_synthetic_phase_a_lease_owner_mismatch'
    }
    Remove-Item -LiteralPath $location.lease_path -Force -ErrorAction Stop
    if (Test-Path -LiteralPath $location.lease_path) {
        throw 'v4_synthetic_phase_a_lease_cleanup_failed'
    }
}
