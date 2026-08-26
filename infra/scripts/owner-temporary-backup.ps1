[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('preflight', 'backup', 'verify')]
    [string]$Action = 'preflight',

    [Parameter(Mandatory = $true)]
    [string]$TargetRoot,

    [Parameter(Mandatory = $true)]
    [ValidateCount(1, 8)]
    [string[]]$ProtectedSourceRootPath,

    [switch]$ConfirmOwnerTemporaryBackup,
    [switch]$AcceptSameDiskTemporaryRecoveryLimitation,

    [string]$BackupId,
    [string]$PiwigoOwnerEnvPath
)

# Owner full-library backup publisher for a deliberately temporary exFAT
# recovery target. It never accepts a photo source as its destination, never
# writes plaintext database/config/media archives to exFAT, and never destroys
# or restores a runtime. Restore orchestration is intentionally separate.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateFullRoot = Join-Path $projectRoot 'infra\private-full'
$helperPath = Join-Path $PSScriptRoot 'create-owner-temporary-backup.sh'
$mlManifestPath = Join-Path $projectRoot 'infra\immich-spike\ml-artifacts\manifest.json'
$upstreamLockPath = Join-Path $projectRoot 'infra\immich-spike\immich-upstream.lock.json'
$secretAclPath = Join-Path $PSScriptRoot 'secret-file-acl.ps1'
$runtimeRoot = Join-Path $projectRoot '.codex-work\private-real-full\runtime\owner-temporary-backup'
$markerName = 'CLASS_ARCHIVE_BACKUP_TARGET'
$expectedMarker = "CLASS_ARCHIVE_BACKUP_TARGET`nversion=1`nscope=OWNER_PRIVATE_FULL`n"
$wsl = "$env:SystemRoot\System32\wsl.exe"
$script:stage = 'initialization'
$script:backupLock = $null
$script:backupLockPath = $null

if ([string]::IsNullOrWhiteSpace($PiwigoOwnerEnvPath)) {
    $PiwigoOwnerEnvPath = Join-Path $privateFullRoot '.env.piwigo.owner'
}

. $secretAclPath

function Stop-OwnerBackup([string]$Code) {
    throw [InvalidOperationException]::new('OWNER_TEMP_BACKUP_STOP:' + $Code)
}

function Normalize-DirectoryPath([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path) -or $Path.IndexOf([char]0) -ge 0) {
        Stop-OwnerBackup 'path_invalid'
    }
    try { return [IO.Path]::GetFullPath($Path).TrimEnd('\', '/') }
    catch { Stop-OwnerBackup 'path_invalid' }
}

function Test-SameOrChild([string]$Candidate, [string]$Parent) {
    $candidateFull = (Normalize-DirectoryPath $Candidate) + [IO.Path]::DirectorySeparatorChar
    $parentFull = (Normalize-DirectoryPath $Parent) + [IO.Path]::DirectorySeparatorChar
    return $candidateFull.StartsWith($parentFull, [StringComparison]::OrdinalIgnoreCase)
}

function Assert-PlainDirectory([string]$Path, [string]$Code) {
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-OwnerBackup $Code
    }
}

function Get-TargetBoundary {
    $target = Normalize-DirectoryPath $TargetRoot
    $root = [IO.Path]::GetPathRoot($target)
    if ([string]::IsNullOrWhiteSpace($root)) { Stop-OwnerBackup 'target_drive_missing' }
    $root = $root.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $parent = [IO.Directory]::GetParent($target)
    if ($null -eq $parent -or -not [string]::Equals(
        $parent.FullName.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar,
        $root,
        [StringComparison]::OrdinalIgnoreCase
    )) {
        Stop-OwnerBackup 'target_must_be_drive_root_child'
    }
    $leaf = [IO.Path]::GetFileName($target)
    if ($leaf -notmatch '\AClassArchive-Temporary-Recovery(?:-[A-Za-z0-9_-]{1,40})?\z') {
        Stop-OwnerBackup 'target_name_invalid'
    }
    $driveLetter = $root.Substring(0, 1).ToUpperInvariant()
    try {
        $disk = Get-CimInstance Win32_LogicalDisk -Filter ("DeviceID='" + $driveLetter + ":'") -ErrorAction Stop
    }
    catch { Stop-OwnerBackup 'target_drive_unavailable' }
    if ($null -eq $disk -or -not [string]::Equals([string]$disk.FileSystem, 'exFAT', [StringComparison]::OrdinalIgnoreCase)) {
        Stop-OwnerBackup 'target_filesystem_not_exfat'
    }
    if ([uint64]$disk.FreeSpace -le 0) { Stop-OwnerBackup 'target_free_space_invalid' }

    $seen = @{}
    foreach ($sourcePath in $ProtectedSourceRootPath) {
        $source = Normalize-DirectoryPath $sourcePath
        if ($seen.ContainsKey($source.ToLowerInvariant())) { Stop-OwnerBackup 'protected_source_duplicate' }
        $seen[$source.ToLowerInvariant()] = $true
        if (-not (Test-Path -LiteralPath $source -PathType Container)) { Stop-OwnerBackup 'protected_source_missing' }
        Assert-PlainDirectory $source 'protected_source_untrusted'
        if ((Test-SameOrChild $target $source) -or (Test-SameOrChild $source $target)) {
            Stop-OwnerBackup 'target_overlaps_protected_source'
        }
    }
    return @{
        target = $target
        root = $root
        drive = $driveLetter
        free_bytes = [uint64]$disk.FreeSpace
        protected_source_count = $seen.Count
    }
}

function Convert-WslSizeToBytes([string]$Value) {
    if ([string]::IsNullOrWhiteSpace($Value)) { return $null }
    $normalized = $Value.Trim().ToUpperInvariant()
    if ($normalized -eq '0') { return [uint64]0 }
    if ($normalized -notmatch '\A([0-9]{1,12})(KB|MB|GB|TB)\z') { return $null }
    $count = [uint64]$Matches[1]
    $multiplier = switch ([string]$Matches[2]) {
        'KB' { [uint64]1KB }
        'MB' { [uint64]1MB }
        'GB' { [uint64]1GB }
        'TB' { [uint64]1TB }
    }
    if ($count -gt ([uint64]::MaxValue / $multiplier)) { return $null }
    return [uint64]($count * $multiplier)
}

function Get-WslSwapCapacityGuard([hashtable]$Boundary) {
    $systemRoot = [IO.Path]::GetPathRoot($env:SystemRoot)
    if ([string]::IsNullOrWhiteSpace($systemRoot) -or $systemRoot -notmatch '\A([A-Za-z]):\\\z') {
        Stop-OwnerBackup 'system_drive_invalid'
    }
    $systemDrive = $Matches[1].ToUpperInvariant()
    try {
        $systemDisk = Get-CimInstance Win32_LogicalDisk -Filter ("DeviceID='" + $systemDrive + ":'") -ErrorAction Stop
    }
    catch { Stop-OwnerBackup 'system_drive_unavailable' }
    if ($null -eq $systemDisk -or [uint64]$systemDisk.FreeSpace -le 0) { Stop-OwnerBackup 'system_drive_free_space_invalid' }

    $settings = @{}
    $configValid = $true
    $configItem = $null
    $configPath = Join-Path $env:USERPROFILE '.wslconfig'
    if (Test-Path -LiteralPath $configPath) {
        $configItem = Get-Item -LiteralPath $configPath -Force -ErrorAction Stop
        if ($configItem.PSIsContainer -or ($configItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
            $configItem.Length -le 0 -or $configItem.Length -gt 65536) {
            $configValid = $false
        }
        else {
            $section = ''
            foreach ($rawLine in [IO.File]::ReadAllLines($configItem.FullName)) {
                $line = $rawLine.Trim()
                if ($line.Length -eq 0 -or $line.StartsWith('#') -or $line.StartsWith(';')) { continue }
                if ($line -match '\A\[([A-Za-z0-9_-]+)\]\z') {
                    $section = $Matches[1].ToLowerInvariant()
                    continue
                }
                if ($section -ne 'wsl2') { continue }
                if ($line -notmatch '\A([A-Za-z][A-Za-z0-9]*)\s*=\s*(.*?)\s*\z') {
                    $configValid = $false
                    continue
                }
                $name = $Matches[1].ToLowerInvariant()
                if ($settings.ContainsKey($name)) {
                    $configValid = $false
                    continue
                }
                $settings[$name] = [string]$Matches[2]
            }
        }
    }
    else { $configValid = $false }

    $configuredSwapBytes = $null
    if ($settings.ContainsKey('swap')) {
        $configuredSwapBytes = Convert-WslSizeToBytes ([string]$settings.swap)
        if ($null -eq $configuredSwapBytes) { $configValid = $false }
    }

    $swapPathOnTargetDrive = $false
    $swapFileTrusted = $false
    $swapPath = $null
    if ($configValid -and $settings.ContainsKey('swapfile')) {
        $rawSwapPath = [string]$settings.swapfile
        if ($rawSwapPath -notmatch '[%$]' -and -not [string]::IsNullOrWhiteSpace($rawSwapPath)) {
            try {
                $swapPath = [IO.Path]::GetFullPath($rawSwapPath)
                $swapRoot = [IO.Path]::GetPathRoot($swapPath)
                if ($swapRoot -match '\A([A-Za-z]):\\\z') {
                    $swapDrive = $Matches[1].ToUpperInvariant()
                    $swapPathOnTargetDrive = $swapDrive -eq [string]$Boundary.drive -and $swapDrive -ne $systemDrive
                    if ($swapPathOnTargetDrive -and (Test-Path -LiteralPath $swapPath -PathType Leaf)) {
                        $swapItem = Get-Item -LiteralPath $swapPath -Force -ErrorAction Stop
                        $swapFileTrusted = -not ($swapItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -and $swapItem.Length -gt 0
                    }
                }
            }
            catch {
                $swapPathOnTargetDrive = $false
                $swapFileTrusted = $false
            }
        }
    }

    # .wslconfig is loaded only when the WSL2 VM starts. Prove this exact file
    # predates the current kernel boot and that the active swap size matches the
    # explicit setting; otherwise text on disk is not evidence of active state.
    $activeSwapCount = [uint64]0
    $activeSwapBytes = [uint64]0
    $configAppliedToVm = $false
    if ($configValid -and $null -ne $configItem -and $null -ne $configuredSwapBytes -and
        [uint64]$configuredSwapBytes -gt 0 -and $swapPathOnTargetDrive -and $swapFileTrusted) {
        $bootLines = @(& $wsl -d Ubuntu --exec awk '/^btime / {print $2}' /proc/stat 2>$null)
        $bootCode = $LASTEXITCODE
        $swapLines = @(& $wsl -d Ubuntu --exec awk 'NR > 1 { count += 1; kib += $3 } END { print count + 0; print kib + 0 }' /proc/swaps 2>$null)
        $swapCode = $LASTEXITCODE
        $bootConfirm = @(& $wsl -d Ubuntu --exec awk '/^btime / {print $2}' /proc/stat 2>$null)
        $bootConfirmCode = $LASTEXITCODE
        if ($bootCode -eq 0 -and $swapCode -eq 0 -and $bootConfirmCode -eq 0 -and
            $bootLines.Count -eq 1 -and $bootConfirm.Count -eq 1 -and $swapLines.Count -eq 2 -and
            [string]$bootLines[0] -match '\A[0-9]{1,20}\z' -and [string]$bootConfirm[0] -eq [string]$bootLines[0] -and
            [string]$swapLines[0] -match '\A[0-9]{1,20}\z' -and [string]$swapLines[1] -match '\A[0-9]{1,20}\z') {
            $activeSwapCount = [uint64]$swapLines[0]
            $activeSwapKiB = [uint64]$swapLines[1]
            if ($activeSwapKiB -le ([uint64]::MaxValue / 1024)) {
                $activeSwapBytes = [uint64]($activeSwapKiB * 1024)
                $bootUtc = [DateTimeOffset]::FromUnixTimeSeconds([int64]$bootLines[0]).UtcDateTime
                $configPredatesVm = $configItem.LastWriteTimeUtc -le $bootUtc
                $sizeDelta = if ($activeSwapBytes -ge [uint64]$configuredSwapBytes) {
                    $activeSwapBytes - [uint64]$configuredSwapBytes
                }
                else { [uint64]$configuredSwapBytes - $activeSwapBytes }
                $configAppliedToVm = $configPredatesVm -and $activeSwapCount -eq 1 -and $sizeDelta -le [uint64](16MB)
            }
        }
    }

    $swapTargetMatch = $swapPathOnTargetDrive -and $swapFileTrusted -and $configAppliedToVm

    $systemRequired = [uint64]0
    $placement = 'TARGET_NON_SYSTEM_DRIVE'
    if (-not $swapTargetMatch) {
        $placement = 'SYSTEM_DRIVE_CAPACITY_FALLBACK'
        if ($null -eq $configuredSwapBytes) {
            $defaultSwap = [uint64](32GB)
            try {
                $computer = Get-CimInstance Win32_ComputerSystem -ErrorAction Stop
                if ($null -ne $computer -and [uint64]$computer.TotalPhysicalMemory -gt 0) {
                    $quarterMemory = [uint64]([uint64]$computer.TotalPhysicalMemory / 4)
                    if ($quarterMemory -gt [uint64](8GB)) { $defaultSwap = $quarterMemory }
                    else { $defaultSwap = [uint64](8GB) }
                }
            }
            catch { }
            $configuredSwapBytes = $defaultSwap
        }
        $systemRequired = [uint64]$configuredSwapBytes + [uint64](10GB)
        if ([uint64]$systemDisk.FreeSpace -lt $systemRequired) {
            Stop-OwnerBackup 'system_drive_wsl_swap_safety_margin_insufficient'
        }
    }

    return @{
        WSL_SWAP_PLACEMENT = $placement
        WSL_SWAP_TARGET_DRIVE_MATCH = [uint64]$(if ($swapTargetMatch) { 1 } else { 0 })
        WSL_SWAP_ACTIVE = [uint64]$(if ($activeSwapCount -gt 0) { 1 } else { 0 })
        WSL_SWAP_ACTIVE_BYTES = $activeSwapBytes
        WSL_CONFIG_APPLIED_TO_VM = [uint64]$(if ($configAppliedToVm) { 1 } else { 0 })
        SYSTEM_DRIVE_FREE_BYTES = [uint64]$systemDisk.FreeSpace
        SYSTEM_DRIVE_REQUIRED_FREE_BYTES = $systemRequired
        SYSTEM_DRIVE_CAPACITY_GUARD = 'PASS'
    }
}

function Assert-IgnoredOwnerFile([string]$Path, [string]$ExpectedName) {
    $resolved = (Resolve-Path -LiteralPath $Path).Path
    $item = Get-Item -LiteralPath $resolved -Force
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-OwnerBackup 'owner_env_untrusted'
    }
    $expectedDirectory = (Resolve-Path -LiteralPath $privateFullRoot).Path.TrimEnd('\', '/')
    if (-not [string]::Equals([IO.Directory]::GetParent($resolved).FullName.TrimEnd('\', '/'), $expectedDirectory, [StringComparison]::OrdinalIgnoreCase) -or
        -not [string]::Equals([IO.Path]::GetFileName($resolved), $ExpectedName, [StringComparison]::Ordinal)) {
        Stop-OwnerBackup 'owner_env_path_invalid'
    }
    $relative = $resolved.Substring($projectRoot.TrimEnd('\', '/').Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-OwnerBackup 'owner_env_not_ignored' }
    if (@(& git -C $projectRoot ls-files -- $relative 2>$null).Count -ne 0) { Stop-OwnerBackup 'owner_env_tracked' }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
    return $resolved
}

function Read-OwnerSecrets([string]$Path) {
    $bytes = [IO.File]::ReadAllBytes($Path)
    if ($bytes.Length -lt 64 -or $bytes.Length -gt 65536) { Stop-OwnerBackup 'owner_env_size_invalid' }
    try { $text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes) }
    catch { Stop-OwnerBackup 'owner_env_encoding_invalid' }
    if ($text.Contains("`0")) { Stop-OwnerBackup 'owner_env_encoding_invalid' }
    $values = @{}
    foreach ($line in ($text -split "`r?`n")) {
        if ([string]::IsNullOrWhiteSpace($line) -or $line.TrimStart().StartsWith('#')) { continue }
        if ($line -notmatch '\A([A-Z][A-Z0-9_]*)=(.*)\z') { Stop-OwnerBackup 'owner_env_line_invalid' }
        $name = [string]$Matches[1]
        $value = [string]$Matches[2]
        if ($values.ContainsKey($name) -or $value.Contains("`r") -or $value.Contains("`n") -or $value.Contains("`0")) {
            Stop-OwnerBackup 'owner_env_value_invalid'
        }
        $values[$name] = $value
    }
    $result = @{}
    foreach ($name in @('DB_PASSWORD', 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET', 'CLASS_ARCHIVE_CLAIM_CODE_PEPPER')) {
        if (-not $values.ContainsKey($name)) { Stop-OwnerBackup 'required_recovery_secret_missing' }
        $value = [string]$values[$name]
        if ($value.Length -lt 32 -or $value.Length -gt 190 -or $value -notmatch '\A[A-Za-z0-9_-]+\z' -or $value -match '\A__.*__\z') {
            Stop-OwnerBackup 'required_recovery_secret_invalid'
        }
        $result[$name] = $value
    }
    return $result
}

function Protect-DpapiString([string]$Value) {
    $secure = ConvertTo-SecureString -String $Value -AsPlainText -Force
    try { return ConvertFrom-SecureString -SecureString $secure }
    finally { if ($secure -is [IDisposable]) { $secure.Dispose() } }
}

function Unprotect-DpapiString([string]$Ciphertext) {
    if ($Ciphertext -notmatch '\A[0-9a-fA-F]{128,}\z') { Stop-OwnerBackup 'dpapi_ciphertext_invalid' }
    $secure = ConvertTo-SecureString -String $Ciphertext
    $pointer = [IntPtr]::Zero
    try {
        $pointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure)
        return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($pointer)
    }
    finally {
        if ($pointer -ne [IntPtr]::Zero) { [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($pointer) }
        if ($secure -is [IDisposable]) { $secure.Dispose() }
    }
}

function New-RandomPassphrase {
    $bytes = New-Object byte[] 64
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    try { return ([Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')) }
    finally { [Array]::Clear($bytes, 0, $bytes.Length) }
}

function Get-WslPath([string]$Path) {
    $lines = @(& $wsl -d Ubuntu --exec wslpath -a $Path 2>&1)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 1 -or [string]$lines[0] -notmatch '\A/mnt/[a-z]/') {
        Stop-OwnerBackup 'wsl_path_conversion_failed'
    }
    return [string]$lines[0]
}

function Invoke-Helper([string[]]$Arguments) {
    if (-not (Test-Path -LiteralPath $helperPath -PathType Leaf)) { Stop-OwnerBackup 'helper_missing' }
    $helperWsl = Get-WslPath $helperPath
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& $wsl -d Ubuntu --exec bash $helperWsl @Arguments 2>&1)
        $code = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($code -ne 0) {
        $safe = @($lines | Where-Object { [string]$_ -match '\AOWNER_TEMP_BACKUP_HELPER=FAIL code=[a-z0-9_]{1,128}\z' }) | Select-Object -Last 1
        if ($null -ne $safe) { throw [InvalidOperationException]::new([string]$safe) }
        Stop-OwnerBackup 'helper_failed'
    }
    return @($lines | ForEach-Object { [string]$_ })
}

function Parse-SafeEvidence([string[]]$Lines) {
    $values = @{}
    foreach ($line in $Lines) {
        if ($line -match '\A([A-Z][A-Z0-9_]*)=([0-9]+)\z') {
            if ($values.ContainsKey($Matches[1])) { Stop-OwnerBackup 'helper_evidence_duplicate' }
            $values[[string]$Matches[1]] = [uint64]$Matches[2]
        }
        elseif ($line -match '\A([A-Z][A-Z0-9_]*)=([A-Za-z0-9@:/._+-]+)\z') {
            if ($values.ContainsKey($Matches[1])) { Stop-OwnerBackup 'helper_evidence_duplicate' }
            $values[[string]$Matches[1]] = [string]$Matches[2]
        }
        elseif ($line -notmatch '\AOWNER_TEMP_BACKUP_HELPER=PASS action=(?:preflight|backup|verify)\z') {
            Stop-OwnerBackup 'helper_output_unsafe'
        }
    }
    return $values
}

function Get-Preflight([hashtable]$Boundary) {
    $lines = Invoke-Helper @('preflight')
    $values = Parse-SafeEvidence $lines
    foreach ($name in @(
        'OWNER_ORIGINAL_BYTES', 'MARIADB_BYTES', 'IMMICH_POSTGRES_BYTES', 'AI_INDEX_BYTES',
        'CONFIG_STATE_BYTES', 'IMMICH_UPLOAD_BYTES', 'EST_BACKUP_BYTES', 'EST_RESTORE_BYTES'
    )) {
        if (-not $values.ContainsKey($name) -or [uint64]$values[$name] -le 0) { Stop-OwnerBackup 'preflight_evidence_invalid' }
    }
    $payload = [uint64]$values.EST_BACKUP_BYTES + [uint64]$values.EST_RESTORE_BYTES
    $tenGiB = [uint64](10GB)
    $margin = [uint64][Math]::Ceiling([double]$payload * 0.15)
    if ($margin -lt $tenGiB) { $margin = $tenGiB }
    $required = $payload + $margin
    if ([uint64]$Boundary.free_bytes -lt $required) { Stop-OwnerBackup 'target_free_space_insufficient' }
    $values['M_FREE_BYTES'] = [uint64]$Boundary.free_bytes
    $values['SAFE_MARGIN_BYTES'] = $margin
    $values['REQUIRED_FREE_BYTES'] = $required
    $hostGuard = Get-WslSwapCapacityGuard $Boundary
    foreach ($name in @(
        'WSL_SWAP_PLACEMENT', 'WSL_SWAP_TARGET_DRIVE_MATCH', 'WSL_SWAP_ACTIVE', 'WSL_SWAP_ACTIVE_BYTES',
        'WSL_CONFIG_APPLIED_TO_VM', 'SYSTEM_DRIVE_FREE_BYTES',
        'SYSTEM_DRIVE_REQUIRED_FREE_BYTES', 'SYSTEM_DRIVE_CAPACITY_GUARD'
    )) { $values[$name] = $hostGuard[$name] }
    $values['ARCHIVE_HELPER_MEMORY_BYTES'] = [uint64](256MB)
    return $values
}

function Initialize-Target([hashtable]$Boundary) {
    $target = [string]$Boundary.target
    $existed = Test-Path -LiteralPath $target
    if (-not $existed) {
        New-Item -ItemType Directory -Path $target -ErrorAction Stop | Out-Null
    }
    Assert-PlainDirectory $target 'target_directory_untrusted'
    $marker = Join-Path $target $markerName
    if (-not (Test-Path -LiteralPath $marker)) {
        if ($existed) { Stop-OwnerBackup 'existing_target_marker_missing' }
        [IO.File]::WriteAllText($marker, $expectedMarker, [Text.UTF8Encoding]::new($false))
    }
    $markerItem = Get-Item -LiteralPath $marker -Force
    if ($markerItem.PSIsContainer -or ($markerItem.Attributes -band [IO.FileAttributes]::ReparsePoint) -or
        -not [string]::Equals([IO.File]::ReadAllText($marker), $expectedMarker, [StringComparison]::Ordinal)) {
        Stop-OwnerBackup 'target_marker_invalid'
    }
    foreach ($name in @('bundles', 'restore-reports')) {
        $directory = Join-Path $target $name
        if (-not (Test-Path -LiteralPath $directory)) { New-Item -ItemType Directory -Path $directory | Out-Null }
        Assert-PlainDirectory $directory 'target_child_untrusted'
    }
    $readme = Join-Path $target 'README-LOCAL.txt'
    $readmeText = @"
Class Archive temporary owner recovery target.

This directory is deliberately outside every photo source and must never be
scanned as an import source or exposed by HTTP. Archives use GPG AES-256 and
retain POSIX metadata inside tar streams. The decryption key is protected by
Windows DPAPI for the creating user.

This is not an independent disaster backup because the recovery package and
the original photo sources reside on the same physical disk. Keep it until an
independent disk or NAS recovery copy has been completed and verified.

The DPAPI recovery envelope also requires the same Windows user profile that
created it. This temporary package alone does not recover from loss of that
profile; establish an independently held recovery key before production.
"@
    [IO.File]::WriteAllText($readme, $readmeText, [Text.UTF8Encoding]::new($false))
    return $target
}

function Assert-CleanCheckout {
    $head = @(& git -C $projectRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $head.Count -ne 1 -or [string]$head[0] -notmatch '\A[0-9a-f]{40}\z') {
        Stop-OwnerBackup 'source_head_invalid'
    }
    $status = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
    if ($LASTEXITCODE -ne 0 -or $status.Count -ne 0) { Stop-OwnerBackup 'checkout_not_clean' }
    return [string]$head[0]
}

function Enter-OwnerBackupLock {
    if (-not (Test-Path -LiteralPath $runtimeRoot)) {
        New-Item -ItemType Directory -Path $runtimeRoot -Force | Out-Null
    }
    Assert-PlainDirectory $runtimeRoot 'backup_runtime_root_untrusted'
    $script:backupLockPath = Join-Path $runtimeRoot 'owner-temporary-backup.lock'
    try {
        $script:backupLock = [IO.File]::Open(
            $script:backupLockPath,
            [IO.FileMode]::OpenOrCreate,
            [IO.FileAccess]::ReadWrite,
            [IO.FileShare]::None
        )
    }
    catch { Stop-OwnerBackup 'backup_already_running' }
}

function Write-Utf8Json([string]$Path, $Value) {
    $json = $Value | ConvertTo-Json -Depth 32 -Compress
    [IO.File]::WriteAllText($Path, $json + "`n", [Text.UTF8Encoding]::new($false))
}

function Test-FixedAsciiEqual([string]$Left, [string]$Right) {
    if ($Left.Length -ne $Right.Length) { return $false }
    $difference = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $difference = $difference -bor (([int][char]$Left[$index]) -bxor ([int][char]$Right[$index]))
    }
    return $difference -eq 0
}

function Get-PayloadSpecs {
    return @(
        @{ id='mariadb'; file='databases/mariadb.sql.gz.gpg'; kind='DATABASE_LOGICAL_GPG'; order=10; posix=$false },
        @{ id='immich_postgres'; file='databases/immich-postgres.dump.gpg'; kind='DATABASE_LOGICAL_GPG'; order=20; posix=$false },
        @{ id='piwigo_data'; file='business-state/piwigo-data.tar.gpg'; kind='POSIX_TAR_GPG'; order=30; posix=$true },
        @{ id='piwigo_scripts'; file='business-state/piwigo-scripts.tar.gpg'; kind='POSIX_TAR_GPG'; order=40; posix=$true },
        @{ id='piwigo_uploads'; file='media-archives/piwigo-uploads.tar.gpg'; kind='POSIX_TAR_GPG'; order=50; posix=$true },
        @{ id='piwigo_galleries'; file='media-archives/piwigo-galleries.tar.gpg'; kind='POSIX_TAR_GPG'; order=60; posix=$true },
        @{ id='immich_upload'; file='immich-state/immich-upload.tar.gpg'; kind='POSIX_TAR_GPG'; order=70; posix=$true },
        @{ id='recovery_secrets'; file='business-state/recovery-secrets.dpapi.json'; kind='WINDOWS_DPAPI_ENVELOPE'; order=5; posix=$false },
        @{ id='runtime_counts'; file='business-state/runtime-counts.json'; kind='SANITIZED_EVIDENCE'; order=1; posix=$false },
        @{ id='immich_upstream_lock'; file='business-state/immich-upstream.lock.json'; kind='NON_SECRET_CONFIGURATION'; order=2; posix=$false },
        @{ id='ml_artifact_manifest'; file='business-state/ml-artifact-manifest.json'; kind='NON_SECRET_CONFIGURATION'; order=3; posix=$false }
    )
}

function Resolve-BackupBundle([string]$Target, [string]$RequestedId) {
    $bundles = Join-Path $Target 'bundles'
    Assert-PlainDirectory $bundles 'target_bundles_untrusted'
    if (-not [string]::IsNullOrWhiteSpace($RequestedId)) {
        if ($RequestedId -notmatch '\Aowner-full-[0-9]{8}T[0-9]{6}Z\z') { Stop-OwnerBackup 'backup_id_invalid' }
        $path = Join-Path $bundles $RequestedId
        if (-not (Test-Path -LiteralPath $path -PathType Container)) { Stop-OwnerBackup 'backup_bundle_missing' }
        Assert-PlainDirectory $path 'backup_bundle_untrusted'
        return $path
    }
    $candidates = @(Get-ChildItem -LiteralPath $bundles -Directory -Force | Where-Object {
        $_.Name -match '\Aowner-full-[0-9]{8}T[0-9]{6}Z\z' -and -not ($_.Attributes -band [IO.FileAttributes]::ReparsePoint)
    } | Sort-Object Name -Descending)
    if ($candidates.Count -lt 1) { Stop-OwnerBackup 'backup_bundle_missing' }
    return $candidates[0].FullName
}

function Verify-ChecksumFile([string]$Bundle) {
    $checksumPath = Join-Path $Bundle 'SHA256SUMS'
    if (-not (Test-Path -LiteralPath $checksumPath -PathType Leaf)) { Stop-OwnerBackup 'checksum_manifest_missing' }
    $expected = @{}
    foreach ($line in [IO.File]::ReadAllLines($checksumPath)) {
        if ($line -notmatch '\A([0-9a-f]{64})  ([A-Za-z0-9._/-]+)\z') { Stop-OwnerBackup 'checksum_manifest_invalid' }
        $relative = [string]$Matches[2]
        if ($relative.StartsWith('/') -or $relative.Contains('../') -or $relative.Contains('/..')) { Stop-OwnerBackup 'checksum_path_unsafe' }
        if ($expected.ContainsKey($relative)) { Stop-OwnerBackup 'checksum_manifest_duplicate' }
        $expected[$relative] = [string]$Matches[1]
    }
    $required = @((Get-PayloadSpecs | ForEach-Object { $_.file }) + @('manifest.json', 'COMPLETE')) | Sort-Object
    if (@(Compare-Object -ReferenceObject $required -DifferenceObject @($expected.Keys | Sort-Object)).Count -ne 0) {
        Stop-OwnerBackup 'checksum_inventory_invalid'
    }
    $bundlePrefix = $Bundle.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    $actualFiles = @()
    foreach ($item in @(Get-ChildItem -LiteralPath $Bundle -Recurse -Force)) {
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { Stop-OwnerBackup 'backup_bundle_reparse_point' }
        if (-not $item.PSIsContainer) {
            $actualFiles += $item.FullName.Substring($bundlePrefix.Length).Replace('\', '/')
        }
    }
    $allowedFiles = @($required + @('SHA256SUMS')) | Sort-Object
    if (@(Compare-Object -ReferenceObject $allowedFiles -DifferenceObject @($actualFiles | Sort-Object)).Count -ne 0) {
        Stop-OwnerBackup 'backup_bundle_inventory_invalid'
    }
    foreach ($relative in $required) {
        $path = Join-Path $Bundle ($relative.Replace('/', '\'))
        $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -le 0) {
            Stop-OwnerBackup 'backup_payload_untrusted'
        }
        $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        if (-not (Test-FixedAsciiEqual $actual ([string]$expected[$relative]))) { Stop-OwnerBackup 'backup_sha256_mismatch' }
    }
}

function New-PlainPassphraseFile([string]$Directory, [string]$Passphrase) {
    if (-not (Test-Path -LiteralPath $Directory)) { New-Item -ItemType Directory -Path $Directory -Force | Out-Null }
    $path = Join-Path $Directory 'gpg-passphrase.txt'
    $stream = [IO.File]::Open($path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    try {
        $bytes = [Text.Encoding]::ASCII.GetBytes($Passphrase + "`n")
        try { $stream.Write($bytes, 0, $bytes.Length); $stream.Flush($true) }
        finally { [Array]::Clear($bytes, 0, $bytes.Length) }
    }
    finally { $stream.Dispose() }
    Set-ClassArchiveOwnerOnlyFileAcl -Path $path
    return $path
}

function Invoke-VerifyBundle([hashtable]$Boundary, [string]$Bundle) {
    Verify-ChecksumFile $Bundle
    $manifestPath = Join-Path $Bundle 'manifest.json'
    try { $manifest = Get-Content -LiteralPath $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-OwnerBackup 'backup_manifest_invalid' }
    if ($manifest.format -ne 'owner-temporary-recovery-v1' -or [int]$manifest.version -ne 1 -or
        $manifest.backup_id -ne [IO.Path]::GetFileName($Bundle) -or
        $manifest.scope -ne 'OWNER_PRIVATE_FULL' -or $manifest.temporary_recovery_target -ne $true -or
        $manifest.independent_disaster_backup -ne $false) {
        Stop-OwnerBackup 'backup_manifest_contract_invalid'
    }
    $archiveEntries = @($manifest.archives)
    $payloadSpecs = @(Get-PayloadSpecs)
    if ($archiveEntries.Count -ne $payloadSpecs.Count) { Stop-OwnerBackup 'backup_manifest_archive_inventory_invalid' }
    $seenArchiveFiles = @{}
    foreach ($spec in $payloadSpecs) {
        $entry = @($archiveEntries | Where-Object { [string]$_.file -eq [string]$spec.file })
        if ($entry.Count -ne 1 -or $seenArchiveFiles.ContainsKey([string]$spec.file)) {
            Stop-OwnerBackup 'backup_manifest_archive_inventory_invalid'
        }
        $seenArchiveFiles[[string]$spec.file] = $true
        $entry = $entry[0]
        $payloadPath = Join-Path $Bundle ([string]$spec.file).Replace('/', '\')
        $payloadItem = Get-Item -LiteralPath $payloadPath -Force -ErrorAction Stop
        $payloadHash = (Get-FileHash -LiteralPath $payloadPath -Algorithm SHA256).Hash.ToLowerInvariant()
        if ([string]$entry.artifact_id -ne [string]$spec.id -or [string]$entry.kind -ne [string]$spec.kind -or
            [int]$entry.restore_order -ne [int]$spec.order -or [uint64]$entry.size -ne [uint64]$payloadItem.Length -or
            [string]$entry.sha256 -notmatch '\A[0-9a-f]{64}\z' -or
            -not (Test-FixedAsciiEqual $payloadHash ([string]$entry.sha256))) {
            Stop-OwnerBackup 'backup_manifest_archive_contract_invalid'
        }
    }
    $envelopePath = Join-Path $Bundle 'business-state\recovery-secrets.dpapi.json'
    try { $envelope = Get-Content -LiteralPath $envelopePath -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-OwnerBackup 'recovery_secret_envelope_invalid' }
    if ([int]$envelope.version -ne 1 -or $envelope.scope -ne 'OWNER_PRIVATE_FULL' -or
        $envelope.protection -ne 'WINDOWS_DPAPI_CURRENT_USER' -or $envelope.dpapi_scope -ne 'CurrentUser') {
        Stop-OwnerBackup 'recovery_secret_envelope_invalid'
    }
    $passphrase = Unprotect-DpapiString ([string]$envelope.protected.gpg_passphrase)
    $verifyRoot = Join-Path $runtimeRoot ([IO.Path]::GetFileName($Bundle) + '-verify')
    $passphrasePath = $null
    try {
        $passphrasePath = New-PlainPassphraseFile $verifyRoot $passphrase
        $lines = Invoke-Helper @('verify', '--bundle', (Get-WslPath $Bundle), '--passphrase-file', (Get-WslPath $passphrasePath))
        [void](Parse-SafeEvidence $lines)
    }
    finally {
        $passphrase = $null
        if ($null -ne $passphrasePath -and (Test-Path -LiteralPath $passphrasePath -PathType Leaf)) { Remove-Item -LiteralPath $passphrasePath -Force }
        if ((Test-Path -LiteralPath $verifyRoot -PathType Container) -and @(Get-ChildItem -LiteralPath $verifyRoot -Force).Count -eq 0) {
            Remove-Item -LiteralPath $verifyRoot -Force
        }
    }
}

try {
    $script:stage = 'target_boundary'
    $boundary = Get-TargetBoundary

    if ($Action -eq 'verify') {
        $script:stage = 'target_open'
        if (-not (Test-Path -LiteralPath $boundary.target -PathType Container)) { Stop-OwnerBackup 'target_not_initialized' }
        Assert-PlainDirectory $boundary.target 'target_directory_untrusted'
        $markerPath = Join-Path $boundary.target $markerName
        if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf) -or
            -not [string]::Equals([IO.File]::ReadAllText($markerPath), $expectedMarker, [StringComparison]::Ordinal)) {
            Stop-OwnerBackup 'target_marker_invalid'
        }
        $script:stage = 'verify_bundle'
        $bundle = Resolve-BackupBundle $boundary.target $BackupId
        Invoke-VerifyBundle $boundary $bundle
        Write-Output ('OWNER_TEMP_BACKUP=PASS action=verify backup_id=' + [IO.Path]::GetFileName($bundle) +
            ' sha256=PASS gpg=PASS temporary_target=YES independent_disaster_backup=NO')
        exit 0
    }

    $script:stage = 'runtime_preflight'
    $preflight = Get-Preflight $boundary
    if ($Action -eq 'preflight') {
        Write-Output ('OWNER_TEMP_BACKUP_PREFLIGHT=PASS OWNER_ORIGINAL_BYTES=' + $preflight.OWNER_ORIGINAL_BYTES +
            ' MARIADB_BYTES=' + $preflight.MARIADB_BYTES +
            ' IMMICH_POSTGRES_BYTES=' + $preflight.IMMICH_POSTGRES_BYTES +
            ' AI_INDEX_BYTES=' + $preflight.AI_INDEX_BYTES +
            ' EST_BACKUP_BYTES=' + $preflight.EST_BACKUP_BYTES +
            ' EST_RESTORE_BYTES=' + $preflight.EST_RESTORE_BYTES +
            ' M_FREE_BYTES=' + $preflight.M_FREE_BYTES +
            ' SAFE_MARGIN_BYTES=' + $preflight.SAFE_MARGIN_BYTES +
            ' WSL_SWAP_PLACEMENT=' + $preflight.WSL_SWAP_PLACEMENT +
            ' WSL_SWAP_TARGET_DRIVE_MATCH=' + $preflight.WSL_SWAP_TARGET_DRIVE_MATCH +
            ' WSL_SWAP_ACTIVE=' + $preflight.WSL_SWAP_ACTIVE +
            ' WSL_SWAP_ACTIVE_BYTES=' + $preflight.WSL_SWAP_ACTIVE_BYTES +
            ' WSL_CONFIG_APPLIED_TO_VM=' + $preflight.WSL_CONFIG_APPLIED_TO_VM +
            ' SYSTEM_DRIVE_FREE_BYTES=' + $preflight.SYSTEM_DRIVE_FREE_BYTES +
            ' SYSTEM_DRIVE_REQUIRED_FREE_BYTES=' + $preflight.SYSTEM_DRIVE_REQUIRED_FREE_BYTES +
            ' SYSTEM_DRIVE_CAPACITY_GUARD=' + $preflight.SYSTEM_DRIVE_CAPACITY_GUARD +
            ' ARCHIVE_HELPER_MEMORY_BYTES=' + $preflight.ARCHIVE_HELPER_MEMORY_BYTES +
            ' filesystem=exFAT temporary_target=YES independent_disaster_backup=NO')
        exit 0
    }

    if (-not $ConfirmOwnerTemporaryBackup.IsPresent) { Stop-OwnerBackup 'backup_confirmation_required' }
    if (-not $AcceptSameDiskTemporaryRecoveryLimitation.IsPresent) { Stop-OwnerBackup 'same_disk_limitation_acknowledgement_required' }
    $script:stage = 'single_instance_lock'
    Enter-OwnerBackupLock
    $script:stage = 'checkout_boundary'
    $sourceHead = Assert-CleanCheckout
    $sourceBranch = @(& git -C $projectRoot branch --show-current 2>$null)
    if ($LASTEXITCODE -ne 0 -or $sourceBranch.Count -ne 1 -or [string]$sourceBranch[0] -notmatch '\A[a-zA-Z0-9._/-]{1,128}\z') {
        Stop-OwnerBackup 'source_branch_invalid'
    }

    $script:stage = 'target_initialize'
    $target = Initialize-Target $boundary
    $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ', [Globalization.CultureInfo]::InvariantCulture)
    $newBackupId = 'owner-full-' + $stamp
    $partial = Join-Path (Join-Path $target 'bundles') ('.partial-' + $newBackupId)
    $published = Join-Path (Join-Path $target 'bundles') $newBackupId
    if ((Test-Path -LiteralPath $partial) -or (Test-Path -LiteralPath $published)) { Stop-OwnerBackup 'backup_bundle_collision' }
    foreach ($relative in @('databases', 'business-state', 'media-archives', 'immich-state')) {
        New-Item -ItemType Directory -Path (Join-Path $partial $relative) -Force | Out-Null
    }

    $secretRoot = Join-Path $runtimeRoot $newBackupId
    $passphrasePath = $null
    $archivePassphrase = $null
    try {
        $script:stage = 'recovery_secret_envelope'
        $ownerEnv = Assert-IgnoredOwnerFile $PiwigoOwnerEnvPath '.env.piwigo.owner'
        $ownerSecrets = Read-OwnerSecrets $ownerEnv
        $archivePassphrase = New-RandomPassphrase
        $passphrasePath = New-PlainPassphraseFile $secretRoot $archivePassphrase
        $secretEnvelope = [ordered]@{
            version = 1
            scope = 'OWNER_PRIVATE_FULL'
            protection = 'WINDOWS_DPAPI_CURRENT_USER'
            dpapi_scope = 'CurrentUser'
            protected = [ordered]@{
                gpg_passphrase = Protect-DpapiString $archivePassphrase
                piwigo_db_password = Protect-DpapiString ([string]$ownerSecrets.DB_PASSWORD)
                anonymous_pseudonym_secret = Protect-DpapiString ([string]$ownerSecrets.CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET)
                claim_code_pepper = Protect-DpapiString ([string]$ownerSecrets.CLASS_ARCHIVE_CLAIM_CODE_PEPPER)
            }
            plaintext_written_to_recovery_target = $false
        }
        Write-Utf8Json (Join-Path $partial 'business-state\recovery-secrets.dpapi.json') $secretEnvelope
        Copy-Item -LiteralPath $mlManifestPath -Destination (Join-Path $partial 'business-state\ml-artifact-manifest.json')
        Copy-Item -LiteralPath $upstreamLockPath -Destination (Join-Path $partial 'business-state\immich-upstream.lock.json')

        $script:stage = 'archive_create'
        $helperLines = Invoke-Helper @(
            'backup', '--bundle', (Get-WslPath $partial), '--passphrase-file', (Get-WslPath $passphrasePath)
        )
        $evidence = Parse-SafeEvidence $helperLines
        $requiredEvidence = @(
            'CLASS_IDENTITY_SCHEMA_VERSION','PIWIGO_VERSION','IMMICH_VERSION','SOURCE_RECORDS','CANONICAL_PHOTOS',
            'PIWIGO_IMAGES','ALBUM_RELATIONSHIPS','LEAF_ALBUMS','COMMENTS','REPLIES','VISIBLE_PEOPLE',
            'PERSON_MERGES','PERSON_RULES','SPOTLIGHTS','MEMORIES','AUDIT_EVENTS','AI_ASSET_INDEX',
            'IMMICH_ASSETS','IMMICH_FACE_RECORDS','IMMICH_RAW_PERSONS','IMMICH_SEARCH_INDEX',
            'OWNER_STATE_SHA256','IMMICH_POSTGRES_STATE_SHA256','IMMICH_UPLOAD_STATE_SHA256','IMMICH_SNAPSHOT_XMAX',
            'MARIADB_IMAGE','PIWIGO_IMAGE','IMMICH_SERVER_IMAGE','IMMICH_ML_IMAGE','POSTGRES_IMAGE'
        )
        foreach ($name in $requiredEvidence) { if (-not $evidence.ContainsKey($name)) { Stop-OwnerBackup 'backup_evidence_missing' } }
        if ([uint64]$evidence.CLASS_IDENTITY_SCHEMA_VERSION -ne 15 -or [string]$evidence.PIWIGO_VERSION -ne '16.4.0' -or
            [string]$evidence.IMMICH_VERSION -ne '3.1.0') { Stop-OwnerBackup 'backup_schema_version_invalid' }

        $counts = [ordered]@{}
        foreach ($name in @(
            'SOURCE_RECORDS','CANONICAL_PHOTOS','PIWIGO_IMAGES','ALBUM_RELATIONSHIPS','LEAF_ALBUMS','COMMENTS','REPLIES',
            'VISIBLE_PEOPLE','PERSON_MERGES','PERSON_RULES','SPOTLIGHTS','MEMORIES','AUDIT_EVENTS','AI_ASSET_INDEX',
            'IMMICH_ASSETS','IMMICH_FACE_RECORDS','IMMICH_RAW_PERSONS','IMMICH_SEARCH_INDEX'
        )) { $counts[$name.ToLowerInvariant()] = [uint64]$evidence[$name] }
        Write-Utf8Json (Join-Path $partial 'business-state\runtime-counts.json') $counts

        $script:stage = 'payload_hash'
        $archives = @()
        foreach ($spec in Get-PayloadSpecs) {
            $path = Join-Path $partial ([string]$spec.file).Replace('/', '\')
            $item = Get-Item -LiteralPath $path -Force -ErrorAction Stop
            if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -or $item.Length -le 0) {
                Stop-OwnerBackup 'backup_payload_missing'
            }
            $hash = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
            $archives += [ordered]@{
                artifact_id = [string]$spec.id
                file = [string]$spec.file
                size = [uint64]$item.Length
                sha256 = $hash
                kind = [string]$spec.kind
                restore_order = [int]$spec.order
                preserves_posix_metadata = [bool]$spec.posix
                encrypted = ([string]$spec.file).EndsWith('.gpg', [StringComparison]::Ordinal)
            }
        }
        $manifest = [ordered]@{
            format = 'owner-temporary-recovery-v1'
            version = 1
            backup_id = $newBackupId
            created_at = (Get-Date).ToUniversalTime().ToString('o', [Globalization.CultureInfo]::InvariantCulture)
            source_head = $sourceHead
            source_branch = [string]$sourceBranch[0]
            scope = 'OWNER_PRIVATE_FULL'
            temporary_recovery_target = $true
            independent_disaster_backup = $false
            filesystem = 'exFAT'
            availability = [ordered]@{
                owner_runtime_reads = 'AVAILABLE_DURING_BACKUP'
                mariadb_writes = 'BRIEFLY_BLOCKED_BY_GLOBAL_READ_LOCK_DURING_LOGICAL_DUMP'
                services_stopped = $false
            }
            host_capacity_guard = [ordered]@{
                wsl_swap_placement = [string]$preflight.WSL_SWAP_PLACEMENT
                wsl_swap_target_drive_match = [bool]([uint64]$preflight.WSL_SWAP_TARGET_DRIVE_MATCH -eq 1)
                wsl_swap_active = [bool]([uint64]$preflight.WSL_SWAP_ACTIVE -eq 1)
                wsl_swap_active_bytes = [uint64]$preflight.WSL_SWAP_ACTIVE_BYTES
                wsl_config_applied_to_vm = [bool]([uint64]$preflight.WSL_CONFIG_APPLIED_TO_VM -eq 1)
                system_drive_free_bytes = [uint64]$preflight.SYSTEM_DRIVE_FREE_BYTES
                system_drive_required_free_bytes = [uint64]$preflight.SYSTEM_DRIVE_REQUIRED_FREE_BYTES
                system_drive_capacity_guard = [string]$preflight.SYSTEM_DRIVE_CAPACITY_GUARD
                archive_helper_memory_bytes = [uint64]$preflight.ARCHIVE_HELPER_MEMORY_BYTES
                private_host_path_recorded = $false
            }
            consistency_guard = [ordered]@{
                strategy = 'ONLINE_LOGICAL_SNAPSHOTS_WITH_BEFORE_AFTER_FAIL_CLOSED_GUARDS'
                owner_state_sha256 = [string]$evidence.OWNER_STATE_SHA256
                immich_postgres_state_sha256 = [string]$evidence.IMMICH_POSTGRES_STATE_SHA256
                immich_upload_state_sha256 = [string]$evidence.IMMICH_UPLOAD_STATE_SHA256
                immich_snapshot_xmax = [uint64]$evidence.IMMICH_SNAPSHOT_XMAX
                mariadb_dump = 'LOCK_ALL_TABLES'
                postgres_dump = 'CONSISTENT_TRANSACTION_SNAPSHOT'
                immich_postgres_guard = 'FULL_LOGICAL_CONTENT_DIGEST_BEFORE_AFTER'
                immich_upload_guard = 'DETERMINISTIC_TAR_DIGEST_BEFORE_ARCHIVE_AFTER'
                state_changed_during_capture = $false
            }
            schema_versions = [ordered]@{ class_identity = 15; piwigo = '16.4.0'; immich = '3.1.0' }
            container_images = [ordered]@{
                piwigo = [string]$evidence.PIWIGO_IMAGE
                mariadb = [string]$evidence.MARIADB_IMAGE
                immich_server = [string]$evidence.IMMICH_SERVER_IMAGE
                immich_machine_learning = [string]$evidence.IMMICH_ML_IMAGE
                postgres = [string]$evidence.POSTGRES_IMAGE
            }
            encryption = [ordered]@{
                archive = 'GPG_SYMMETRIC_AES256'
                compression = 'NONE_FOR_TAR_GZIP_FOR_MARIADB_PG_CUSTOM'
                key_protection = 'WINDOWS_DPAPI_CURRENT_USER'
                plaintext_archive_on_exfat = $false
            }
            archives = $archives
            counts = $counts
            excluded_rebuildable = @(
                'piwigo_derivative_cache', 'immich_model_binaries', 'browser_cache', 'temporary_logs',
                'runtime_locks', 'web_sessions', 'immich_gateway_secret'
            )
            secret_policy = [ordered]@{
                recovery_key_boundary = 'SAME_WINDOWS_CURRENTUSER_PROFILE_REQUIRED'
                windows_profile_loss = 'NOT_RECOVERABLE_FROM_THIS_TEMPORARY_PACKAGE_ALONE'
                piwigo_database_password = 'PRESERVED_BY_DPAPI'
                database_root_credentials = 'REGENERATE'
                immich_database_password = 'REGENERATE'
                piwigo_database_config = 'REGENERATE_FROM_DPAPI_PIWIGO_DATABASE_PASSWORD'
                immich_gateway_token = 'ROTATE_AND_REBIND'
                outstanding_claim_tokens = 'PRESERVED_BY_DPAPI_CLAIM_PEPPER'
                anonymous_pseudonyms = 'PRESERVED_BY_DPAPI_PSEUDONYM_SECRET'
                raw_secrets_in_manifest = $false
            }
            restore_runtime = [ordered]@{
                must_use_fresh_volumes = $true
                current_owner_runtime_must_not_be_destroyed = $true
                model_cache = 'RESTORE_FROM_VERIFIED_LOCAL_ARTIFACT_MANIFEST'
                derivatives = 'REBUILD_AFTER_MEDIA_AND_DATABASE_RESTORE'
            }
        }
        Write-Utf8Json (Join-Path $partial 'manifest.json') $manifest
        [IO.File]::WriteAllText((Join-Path $partial 'COMPLETE'), $newBackupId + "`n", [Text.UTF8Encoding]::new($false))

        $checksumFiles = @((Get-PayloadSpecs | ForEach-Object { [string]$_.file }) + @('manifest.json', 'COMPLETE')) | Sort-Object
        $checksumLines = foreach ($relative in $checksumFiles) {
            $path = Join-Path $partial $relative.Replace('/', '\')
            ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() + '  ' + $relative)
        }
        [IO.File]::WriteAllText((Join-Path $partial 'SHA256SUMS'), ($checksumLines -join "`n") + "`n", [Text.UTF8Encoding]::new($false))

        $script:stage = 'payload_verify'
        Verify-ChecksumFile $partial
        $verifyLines = Invoke-Helper @('verify', '--bundle', (Get-WslPath $partial), '--passphrase-file', (Get-WslPath $passphrasePath))
        [void](Parse-SafeEvidence $verifyLines)
        if (@(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null).Count -ne 0 -or
            [string](@(& git -C $projectRoot rev-parse HEAD 2>$null)[0]) -ne $sourceHead) {
            Stop-OwnerBackup 'checkout_changed_during_backup'
        }
        Move-Item -LiteralPath $partial -Destination $published
        Invoke-VerifyBundle $boundary $published
        $bundleBytes = [uint64](Get-ChildItem -LiteralPath $published -Recurse -File -Force | Measure-Object -Property Length -Sum).Sum
        Write-Output ('OWNER_TEMP_BACKUP=PASS action=backup backup_id=' + $newBackupId +
            ' bundle_bytes=' + $bundleBytes + ' sha256=PASS gpg=PASS source_head=' + $sourceHead +
            ' temporary_target=YES independent_disaster_backup=NO')
    }
    finally {
        $archivePassphrase = $null
        if ($null -ne $passphrasePath -and (Test-Path -LiteralPath $passphrasePath -PathType Leaf)) {
            Remove-Item -LiteralPath $passphrasePath -Force
        }
        if ((Test-Path -LiteralPath $secretRoot -PathType Container) -and @(Get-ChildItem -LiteralPath $secretRoot -Force).Count -eq 0) {
            Remove-Item -LiteralPath $secretRoot -Force
        }
    }
}
catch {
    $code = $null
    if ($_.Exception.Message -match '\AOWNER_TEMP_BACKUP_STOP:([a-z0-9_]{1,128})\z') { $code = [string]$Matches[1] }
    elseif ($_.Exception.Message -match '\AOWNER_TEMP_BACKUP_HELPER=FAIL code=([a-z0-9_]{1,128})\z') { $code = [string]$Matches[1] }
    if ($null -eq $code) { $code = 'unexpected_failure' }
    Write-Error ('OWNER_TEMP_BACKUP=FAIL stage=' + $script:stage + ' code=' + $code)
    exit 1
}
finally {
    if ($null -ne $script:backupLock) {
        $script:backupLock.Dispose()
        $script:backupLock = $null
    }
    if ($null -ne $script:backupLockPath -and (Test-Path -LiteralPath $script:backupLockPath -PathType Leaf)) {
        Remove-Item -LiteralPath $script:backupLockPath -Force -ErrorAction SilentlyContinue
    }
}
