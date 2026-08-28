[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('prepare', 'copy', 'verify', 'all')]
    [string]$Action = 'all',

    # Required only for prepare/all. It is an ignored local inventory that may
    # contain source paths, so this script never prints it or passes it to a
    # Docker environment variable.
    [string]$InventoryPath,

    # Display labels are business-visible private album roots, not filesystem
    # paths. They are used only by the path-free runtime manifest.
    [string]$CollectionLabelA,
    [string]$CollectionLabelB,

    # Required only for copy/verify/all. This must be the separately managed
    # opaque staging root, never either original source root.
    [string]$StagingPath,

    [switch]$Replace
)

# Windows entry point for the full private-library manifest/copy protocol.
# It intentionally knows neither a source path nor a source label. Source
# roots live only in the ignored inventory consumed by the Python tool. All
# user-visible output is an allowlisted stage/count result with no local path
# or basename disclosure.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateRoot = Join-Path $projectRoot '.codex-work\private-real-full'
$manifestDirectory = Join-Path $privateRoot 'manifests'
$inventoryDirectory = Join-Path $privateRoot 'inventory'
$runtimeManifest = Join-Path $manifestDirectory 'full-real-import-manifest.json'
$sourceJournal = Join-Path $manifestDirectory 'full-real-source-journal.json'
$inventorySnapshot = Join-Path $inventoryDirectory 'full-real-source-inventory.json'
$pythonTool = Join-Path $PSScriptRoot 'private-real-full-library.py'
$script:stage = 'initialization'

. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-PrivateFullLibrary([string]$Code) {
    Write-Output "PRIVATE_FULL_LIBRARY_PREPARE=FAIL stage=$script:stage code=$Code"
    exit 2
}

function Get-ProjectRelative([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw 'private_path_outside_checkout'
    }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredUntrackedLeaf([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw ($Code + '_path_untrusted')
    }
    $relative = Get-ProjectRelative $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { throw ($Code + '_not_ignored') }
    $tracked = @(& git -C $projectRoot ls-files -- $relative 2>$null)
    if ($LASTEXITCODE -ne 0 -or $tracked.Count -ne 0) { throw ($Code + '_is_tracked') }
    return $item.FullName
}

function Assert-PrivateLabel([string]$Value, [string]$Code) {
    $label = $Value.Trim()
    $invalid = [string]::IsNullOrWhiteSpace($label) -or $label.Length -gt 190 -or $label.Contains('/') `
        -or $label.Contains([string][char]92) -or $label.Contains([string][char]0) `
        -or $label -match '^[A-Za-z]:' -or $label -match '[\x00-\x1F\x7F]'
    if ($invalid) {
        throw ($Code + '_invalid')
    }
    return $label
}

function Set-ClassArchiveOwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'private_directory_path_untrusted'
    }
    # Reapplying an unchanged Windows security descriptor can require a
    # privilege that a normal owner process deliberately does not hold.  First
    # prove that an existing directory already satisfies the same strict ACL;
    # only replace a descriptor that fails the shared assertion.
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    }
    catch {
        # Construct and apply the explicit owner/SYSTEM/Administrators-only
        # descriptor below.  Any failure remains fatal to the private protocol.
    }
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) { throw 'private_acl_identity_unavailable' }
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        $rule = [Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        )
        [void]$acl.AddAccessRule($rule)
    }
    Set-Acl -LiteralPath $resolved -AclObject $acl
    # The shared assertion is type-agnostic: on an explicit DirectorySecurity
    # descriptor it proves the same owner/SYSTEM/Administrators-only rule set
    # before a Python temporary file can inherit from this directory.
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function Ensure-PrivateWorkRoot {
    if (-not (Test-Path -LiteralPath $privateRoot)) {
        [void](New-Item -ItemType Directory -Path $privateRoot -Force -ErrorAction Stop)
    }
    foreach ($directory in @($privateRoot, $manifestDirectory, $inventoryDirectory)) {
        if (-not (Test-Path -LiteralPath $directory)) {
            [void](New-Item -ItemType Directory -Path $directory -Force -ErrorAction Stop)
        }
        Set-ClassArchiveOwnerOnlyDirectoryAcl $directory
    }
    $relative = Get-ProjectRelative $privateRoot
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { throw 'private_work_root_not_ignored' }
}

function Protect-PrivateArtifacts {
    foreach ($artifact in @($runtimeManifest, $sourceJournal, $inventorySnapshot)) {
        $full = Assert-IgnoredUntrackedLeaf $artifact 'private_artifact'
        Set-ClassArchiveOwnerOnlyFileAcl -Path $full
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $full
    }
}

function Resolve-Python {
    $candidate = Get-Command python.exe -ErrorAction SilentlyContinue
    if ($null -eq $candidate) { $candidate = Get-Command python -ErrorAction SilentlyContinue }
    if ($null -eq $candidate) { throw 'python_unavailable' }
    return [string]$candidate.Source
}

function Invoke-FullLibraryPython([string[]]$Arguments, [string]$ExpectedPrefix, [string]$Code) {
    $python = Resolve-Python
    # Capturing output prevents an accidental source path from reaching the
    # terminal even if a local Python dependency fails noisily.
    $result = @(& $python $pythonTool @Arguments 2>&1)
    if ($LASTEXITCODE -ne 0) { throw ($Code + '_failed') }
    $matches = @($result | Where-Object { [string]$_ -like ($ExpectedPrefix + '*') })
    if ($matches.Count -ne 1) { throw ($Code + '_result_invalid') }
    # The Python protocol emits only fixed, path-free counters here.  Surface
    # that success line so a local operator can observe resumability without
    # exposing captured stderr, source paths, filenames, or private metadata.
    Write-Output ([string]$matches[0])
}

try {
    $script:stage = 'work_root'
    Ensure-PrivateWorkRoot

    if ($Action -in @('prepare', 'all')) {
        $script:stage = 'prepare_input'
        if ([string]::IsNullOrWhiteSpace($InventoryPath)) { throw 'inventory_missing' }
        $inventory = Assert-IgnoredUntrackedLeaf $InventoryPath 'inventory'
        Set-ClassArchiveOwnerOnlyFileAcl -Path $inventory
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $inventory
        $labelA = Assert-PrivateLabel $CollectionLabelA 'collection_label_a'
        $labelB = Assert-PrivateLabel $CollectionLabelB 'collection_label_b'
        $arguments = @(
            'prepare', '--inventory', $inventory, '--output', $privateRoot,
            '--collection-label', ('PRIVATE_SOURCE_A=' + $labelA),
            '--collection-label', ('PRIVATE_SOURCE_B=' + $labelB)
        )
        if ($Replace) { $arguments += '--replace' }
        $script:stage = 'prepare_manifest'
        Invoke-FullLibraryPython -Arguments $arguments -ExpectedPrefix 'PRIVATE_FULL_LIBRARY_MANIFEST=PASS' -Code 'manifest_prepare'
        $script:stage = 'protect_artifacts'
        Protect-PrivateArtifacts
    }

    if ($Action -in @('copy', 'verify', 'all')) {
        $script:stage = 'staging_input'
        if ([string]::IsNullOrWhiteSpace($StagingPath)) { throw 'staging_missing' }
        $staging = (Resolve-Path -LiteralPath $StagingPath -ErrorAction Stop).Path
        $stagingItem = Get-Item -LiteralPath $staging -Force -ErrorAction Stop
        if (-not $stagingItem.PSIsContainer -or ($stagingItem.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            throw 'staging_path_untrusted'
        }
        $script:stage = 'protect_artifacts'
        Protect-PrivateArtifacts
        if ($Action -in @('copy', 'all')) {
            $script:stage = 'copy_staging'
            Invoke-FullLibraryPython -Arguments @('copy', '--manifest', $runtimeManifest, '--output', $privateRoot, '--staging', $staging) -ExpectedPrefix 'PRIVATE_FULL_LIBRARY_COPY=PASS' -Code 'staging_copy'
            $script:stage = 'protect_artifacts'
            Protect-PrivateArtifacts
        }
        if ($Action -in @('verify', 'all')) {
            $script:stage = 'verify_staging'
            Invoke-FullLibraryPython -Arguments @('verify', '--manifest', $runtimeManifest, '--output', $privateRoot, '--staging', $staging) -ExpectedPrefix 'PRIVATE_FULL_LIBRARY_VERIFY=PASS' -Code 'staging_verify'
            $script:stage = 'protect_artifacts'
            Protect-PrivateArtifacts
        }
    }

    Write-Output ('PRIVATE_FULL_LIBRARY_PREPARE=PASS action=' + $Action + ' artifact_acl=OWNER_ONLY source_paths=NOT_PRINTED')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^[a-z0-9_]{1,96}$') { $message } else { 'private_full_prepare_failed' }
    Stop-PrivateFullLibrary $code
}
