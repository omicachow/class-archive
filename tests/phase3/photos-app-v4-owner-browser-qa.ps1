[CmdletBinding()]
param(
    # This is an audited, time-bounded lease of one already-frozen FQA
    # aggregate. It never creates or deletes a business record.
    [switch]$ConfirmFqaCredentialLease
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $ConfirmFqaCredentialLease) {
    Write-Output 'V4_OWNER_FQA_CHROME_QA=BLOCKED code=explicit_fqa_credential_lease_confirmation_required'
    exit 3
}

# The ordinary AdminService identity mutation path now participates in the
# durable test-only lease plus exact identity lock_version CAS. Acquisition is
# still disabled by default and enabled only in this one localhost broker.
$runtimeLeaseMutationExclusionProven = $true
if (-not $runtimeLeaseMutationExclusionProven) {
    Write-Output 'V4_OWNER_FQA_CHROME_QA=BLOCKED code=lease_runtime_disabled_pending_mutation_exclusion'
    exit 4
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$separator = [IO.Path]::DirectorySeparatorChar
$coreOrigin = 'http://127.0.0.1:8190/'
$photoOrigin = 'http://127.0.0.1:8191/'
$envRelative = 'infra/private-full/.env.piwigo.owner'
$composeProject = 'class_archive_private_full_v3_piwigo'
$composeFiles = @('infra/docker-compose.yml', 'infra/private-full/docker-compose.override.yml')
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner-fqa-lease'))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\browser\photos-app-v4-owner-fqa-lease'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\photos-app-v4'))
$leaseTtlSeconds = 900
$browserTimeoutSeconds = 720
$brokerCloseTimeoutSeconds = 120
$recoveryTimeoutSeconds = 60

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerFqa([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_FQA_STOP:' + $Code)
}

function Assert-NoReparseAncestor([string]$Candidate, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $boundary = $projectRoot.TrimEnd('\', '/')
    $cursor = $full
    while (-not [string]::IsNullOrWhiteSpace($cursor) -and -not (Test-Path -LiteralPath $cursor)) {
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
    while (-not [string]::IsNullOrWhiteSpace($cursor)) {
        $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
        if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) {
            Stop-V4OwnerFqa ($Code + '_reparse_ancestor')
        }
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $boundary, [StringComparison]::OrdinalIgnoreCase)) {
            return
        }
        $parent = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
    Stop-V4OwnerFqa ($Code + '_ancestor_outside_project')
}

function Assert-PrivateParentAcl([string]$Candidate, [string]$Code) {
    Assert-NoReparseAncestor -Candidate $Candidate -Code $Code
    $parent = [IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($Candidate))
    if ([string]::IsNullOrWhiteSpace($parent) -or -not (Test-Path -LiteralPath $parent -PathType Container)) {
        Stop-V4OwnerFqa ($Code + '_parent_unavailable')
    }
    $trustedRoot = $runtimeRoot.TrimEnd('\', '/')
    $trustedBoundary = $trustedRoot + $separator
    $cursor = [IO.Path]::GetFullPath($parent).TrimEnd('\', '/')
    if (-not ([string]::Equals($cursor, $trustedRoot, [StringComparison]::OrdinalIgnoreCase)) -and -not ($cursor.StartsWith($trustedBoundary, [StringComparison]::OrdinalIgnoreCase))) {
        Stop-V4OwnerFqa ($Code + '_parent_outside_private_root')
    }
    while ($true) {
        $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
        if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Stop-V4OwnerFqa ($Code + '_parent_reparse')
        }
        try { Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName }
        catch { Stop-V4OwnerFqa ($Code + '_parent_acl_invalid') }
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $trustedRoot, [StringComparison]::OrdinalIgnoreCase)) {
            return
        }
        $next = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $next) { Stop-V4OwnerFqa ($Code + '_parent_chain_invalid') }
        $cursor = $next.FullName.TrimEnd('\', '/')
    }
}

function Set-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerFqa 'private_directory_untrusted'
    }
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    } catch {}
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) { Stop-V4OwnerFqa 'private_directory_identity_unavailable' }
    $systemSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-18')
    $administratorsSid = [Security.Principal.SecurityIdentifier]::new('S-1-5-32-544')
    $acl = [Security.AccessControl.DirectorySecurity]::new()
    $acl.SetOwner($identity)
    $acl.SetAccessRuleProtection($true, $false)
    $inheritance = [Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [Security.AccessControl.InheritanceFlags]::ObjectInherit
    foreach ($sid in @($identity, $systemSid, $administratorsSid)) {
        [void]$acl.AddAccessRule([Security.AccessControl.FileSystemAccessRule]::new(
            $sid,
            [Security.AccessControl.FileSystemRights]::FullControl,
            $inheritance,
            [Security.AccessControl.PropagationFlags]::None,
            [Security.AccessControl.AccessControlType]::Allow
        ))
    }
    # A redirected Windows PowerShell -NoProfile child may not have the
    # Security cmdlet module loaded. Prefer the native directory ACL API so
    # the temporary private browser root is protected before any lease data is
    # written; use Set-Acl only as a verified fallback.
    $directorySet = [System.IO.Directory].GetMethod(
        'SetAccessControl',
        [type[]]@([string], [Security.AccessControl.DirectorySecurity])
    )
    if ($null -ne $directorySet) {
        [System.IO.Directory]::SetAccessControl($resolved, $acl)
    }
    elseif ($null -ne (Get-Command Set-Acl -CommandType Cmdlet -ErrorAction SilentlyContinue)) {
        Set-Acl -LiteralPath $resolved -AclObject $acl
    }
    else {
        Stop-V4OwnerFqa 'private_directory_acl_backend_unavailable'
    }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function New-RunId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Assert-IgnoredPrivateChild([string]$Candidate, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + $separator
    $rootBoundary = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + $separator
    if (-not $full.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerFqa ($Code + '_outside_project')
    }
    if (-not $full.StartsWith($rootBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerFqa ($Code + '_outside_root')
    }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4OwnerFqa ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) {
        Stop-V4OwnerFqa ($Code + '_tracked')
    }
    Assert-NoReparseAncestor -Candidate $full -Code $Code
    return $full
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerFqa ($Code + '_untrusted')
    }
    Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerFqa ($Code + '_untrusted')
    }
    $full = Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object {
        $_.Attributes -band [IO.FileAttributes]::ReparsePoint
    })
    if ($reparse.Count -ne 0) { Stop-V4OwnerFqa ($Code + '_contains_reparse') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

function Get-NodePath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $node = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { Stop-V4OwnerFqa 'node_unavailable' }
    return $node
}

function Get-NodeModulesPath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $modules = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $modules -PathType Container)) { Stop-V4OwnerFqa 'node_modules_unavailable' }
    return $modules
}

function Get-ComposeArguments([string[]]$Tail) {
    $arguments = [Collections.Generic.List[string]]::new()
    foreach ($argument in @('-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose', '--env-file', $envRelative)) {
        $arguments.Add($argument)
    }
    foreach ($file in $composeFiles) { $arguments.Add('-f'); $arguments.Add($file) }
    $arguments.Add('-p'); $arguments.Add($composeProject)
    foreach ($argument in $Tail) { $arguments.Add($argument) }
    return [string[]]$arguments.ToArray()
}

function Invoke-BoundedPiwigo([string[]]$Arguments, [int]$TimeoutSeconds, [string]$Code) {
    $wslArguments = Add-ClassArchiveWslTimeout -Arguments (Get-ComposeArguments $Arguments) -TimeoutSeconds $TimeoutSeconds
    $result = Invoke-ClassArchiveBoundedNative `
        -Executable "$env:SystemRoot\System32\wsl.exe" `
        -Arguments $wslArguments `
        -TimeoutSeconds ($TimeoutSeconds + 15) `
        -WorkingDirectory $projectRoot
    if ($result.TimedOut -or $result.ExitCode -ne 0) { Stop-V4OwnerFqa $Code }
    return $result
}

function Initialize-FqaDurableRecoveryRoot {
    $root = '/var/lib/class-archive-private-e2e'
    $script = 'mountpoint -q -- ' + $root +
        ' && install -d -o nginx -g nginx -m 0700 -- ' + $root +
        ' && test "$(stat -c %U:%G:%a -- ' + $root + ')" = "nginx:nginx:700"'
    [void](Invoke-BoundedPiwigo -Arguments @(
        'exec', '-T', '--user', 'root', 'piwigo',
        'sh', '-ec', $script
    ) -TimeoutSeconds 30 -Code 'durable_recovery_root_unavailable')
}

function ConvertFrom-FqaBase64Url([string]$Value) {
    if ($Value -notmatch '^[A-Za-z0-9_-]{2,131072}$') {
        Stop-V4OwnerFqa 'credential_transport_invalid'
    }
    $standard = $Value.Replace('-', '+').Replace('_', '/')
    switch ($standard.Length % 4) {
        0 { }
        2 { $standard += '==' }
        3 { $standard += '=' }
        default { Stop-V4OwnerFqa 'credential_transport_invalid' }
    }
    try {
        return ,([Convert]::FromBase64String($standard))
    }
    catch {
        Stop-V4OwnerFqa 'credential_transport_invalid'
    }
    finally {
        $standard = ''
    }
}

function ConvertFrom-FqaSha256Hex([string]$Value) {
    if ($Value -notmatch '^[a-f0-9]{64}$') {
        Stop-V4OwnerFqa 'credential_hash_invalid'
    }
    $bytes = New-Object byte[] 32
    for ($index = 0; $index -lt 32; $index++) {
        try {
            $bytes[$index] = [Convert]::ToByte($Value.Substring($index * 2, 2), 16)
        }
        catch {
            [Array]::Clear($bytes, 0, $bytes.Length)
            Stop-V4OwnerFqa 'credential_hash_invalid'
        }
    }
    return ,$bytes
}

function Test-FqaFixedTimeBytes([byte[]]$Left, [byte[]]$Right) {
    if ($null -eq $Left -or $null -eq $Right -or $Left.Length -ne $Right.Length) {
        return $false
    }
    # Windows PowerShell 5.1 can run against a framework without
    # CryptographicOperations.FixedTimeEquals. Keep the comparison length
    # independent and do not short-circuit on the first mismatched byte.
    [int]$different = 0
    for ($index = 0; $index -lt $Left.Length; $index++) {
        $different = $different -bor ($Left[$index] -bxor $Right[$index])
    }
    return $different -eq 0
}

function Assert-FqaBrowserCredentialDocument([byte[]]$Bytes, [string]$Run) {
    if ($null -eq $Bytes -or $Bytes.Length -lt 128 -or $Bytes.Length -gt 65536) {
        Stop-V4OwnerFqa 'credential_size_invalid'
    }
    $documentText = ''
    try {
        try {
            $utf8 = [Text.UTF8Encoding]::new($false, $true)
            $documentText = $utf8.GetString($Bytes)
        }
        catch {
            Stop-V4OwnerFqa 'credential_document_invalid'
        }
        if ($documentText.IndexOf([char]0) -ge 0) {
            Stop-V4OwnerFqa 'credential_document_invalid'
        }
        try {
            $document = $documentText | ConvertFrom-Json -ErrorAction Stop
        }
        catch {
            Stop-V4OwnerFqa 'credential_document_invalid'
        }
        if ($null -eq $document) { Stop-V4OwnerFqa 'credential_document_invalid' }
        $rootKeys = @($document.PSObject.Properties.Name | Sort-Object)
        $documentVersion = [int]$document.version
        $documentEnvironment = [string]$document.environment
        $documentRun = [string]$document.run
        $documentScopeValid = (($rootKeys -join ',') -eq 'environment,lease,roles,run,version') -and
            ($documentVersion -eq 1) -and
            ($documentEnvironment -eq 'PRIVATE_REAL_FULL_OWNER_V4_FQA_BROWSER_EXPORT') -and
            ($documentRun -eq $Run)
        if (-not $documentScopeValid) {
            Stop-V4OwnerFqa 'credential_document_scope'
        }
        if ($null -eq $document.lease) { Stop-V4OwnerFqa 'credential_document_scope' }
        $leaseKeys = @($document.lease.PSObject.Properties.Name | Sort-Object)
        $leaseRoster = [string]$document.lease.roster
        $leaseRoles = [int]$document.lease.roles
        $leaseScopeValid = (($leaseKeys -join ',') -eq 'roles,roster') -and
            ($leaseRoster -eq 'FQA-C-99CA3B3B6AF1') -and
            ($leaseRoles -eq 3)
        if (-not $leaseScopeValid) {
            Stop-V4OwnerFqa 'credential_document_scope'
        }
        if ($null -eq $document.roles) { Stop-V4OwnerFqa 'credential_role_shape_invalid' }
        $roles = @($document.roles.PSObject.Properties.Name | Sort-Object)
        if (($roles -join ',') -ne 'anonymous,classmate,family') {
            Stop-V4OwnerFqa 'credential_role_shape_invalid'
        }
        foreach ($role in @('classmate', 'family', 'anonymous')) {
            $credential = $document.roles.$role
            if ($null -eq $credential) { Stop-V4OwnerFqa 'credential_role_shape_invalid' }
            $credentialKeys = @($credential.PSObject.Properties.Name | Sort-Object)
            if (($credentialKeys -join ',') -ne 'password,username') {
                Stop-V4OwnerFqa 'credential_role_shape_invalid'
            }
            $username = [string]$credential.username
            $validUsername = switch ($role) {
                'classmate' { $username -eq 'fqa_99ca3b3b6af1_classmate' }
                'family' { $username -eq 'fqa_99ca3b3b6af1_family' }
                default { $username -match '^anon_[a-f0-9]{20}$' }
            }
            $passwordValid = ([string]$credential.password) -match '^[A-Za-z0-9_-]{64}$'
            if (-not $validUsername -or -not $passwordValid) {
                Stop-V4OwnerFqa 'credential_role_invalid'
            }
        }
    }
    finally {
        $document = $null
        $documentText = ''
    }
}

function Copy-FqaCredentialFromBroker([Diagnostics.Process]$Broker, [string]$Run, [string]$HostPath) {
    $script:wrapperStage = 'credential_copy_preflight'
    if ($null -eq $Broker -or $Broker.HasExited) {
        Stop-V4OwnerFqa 'credential_broker_unavailable'
    }
    if (Test-Path -LiteralPath $HostPath) { Stop-V4OwnerFqa 'credential_path_not_fresh' }
    Assert-PrivateParentAcl -Candidate $HostPath -Code 'credential'

    # Create an empty file inside the already owner-only directory, restrict it,
    # verify the descriptor, and only then write any credential bytes.
    $script:wrapperStage = 'credential_copy_create'
    $created = [IO.File]::Open($HostPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $created.Dispose()
    try {
        $script:wrapperStage = 'credential_copy_acl'
        Set-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
        # Use the already authenticated broker control pipe rather than a
        # second docker exec. The broker validates its 0600 durable
        # recovery-only document, exports a browser-only v1 document exactly
        # once for this run, and remains alive for the eventual close/refreeze
        # attestation.
        $script:wrapperStage = 'credential_broker_export'
        try {
            $Broker.StandardInput.WriteLine('EXPORT ' + $Run)
            $Broker.StandardInput.Flush()
        } catch {
            Stop-V4OwnerFqa 'credential_broker_unavailable'
        }
        $exportTask = $Broker.StandardOutput.ReadLineAsync()
        if (-not $exportTask.Wait([TimeSpan]::FromSeconds(30))) {
            Stop-V4OwnerFqa 'credential_export_timeout'
        }
        $record = [string]$exportTask.Result
        $frame = [regex]::Match($record, '^V4_OWNER_FQA_CREDENTIAL=v1:([a-f0-9]{24}):([1-9][0-9]{0,4}):([a-f0-9]{64}):([A-Za-z0-9_-]{2,131072})$')
        if (-not $frame.Success) {
            $failure = [regex]::Match($record, '^V4_OWNER_FQA_LEASE=FAIL stage=(?:bootstrap|runtime) code=([a-z0-9_]{1,80})$')
            if ($failure.Success) {
                # The code is a broker-controlled lower-case diagnostic token,
                # not a credential or container diagnostic. Preserve it only
                # so a failed export can be repaired without printing broker
                # stderr or any payload bytes.
                Stop-V4OwnerFqa ('credential_export_rejected_' + [string]$failure.Groups[1].Value)
            }
            Stop-V4OwnerFqa 'credential_export_invalid'
        }
        $frameRun = [string]$frame.Groups[1].Value
        $frameLength = 0
        $frameLengthParsed = [int]::TryParse([string]$frame.Groups[2].Value, [ref]$frameLength)
        if (($frameRun -ne $Run) -or (-not $frameLengthParsed) -or ($frameLength -lt 128) -or ($frameLength -gt 65536)) {
            Stop-V4OwnerFqa 'credential_export_scope_invalid'
        }
        $frameHash = [string]$frame.Groups[3].Value
        $encoded = [string]$frame.Groups[4].Value
        $frame = $null
        $record = ''
        $bytes = $null
        $actualHash = $null
        $expectedHash = $null
        try {
            $script:wrapperStage = 'credential_copy_decode'
            $bytes = ConvertFrom-FqaBase64Url -Value $encoded
            if ($bytes.Length -ne $frameLength) { Stop-V4OwnerFqa 'credential_size_invalid' }
            $hashAlgorithm = [Security.Cryptography.SHA256]::Create()
            try { $actualHash = $hashAlgorithm.ComputeHash($bytes) }
            finally { $hashAlgorithm.Dispose() }
            $expectedHash = ConvertFrom-FqaSha256Hex -Value $frameHash
            if (-not (Test-FqaFixedTimeBytes -Left $actualHash -Right $expectedHash)) {
                Stop-V4OwnerFqa 'credential_hash_mismatch'
            }
            $script:wrapperStage = 'credential_copy_validate'
            Assert-FqaBrowserCredentialDocument -Bytes $bytes -Run $Run
            $script:wrapperStage = 'credential_copy_write'
            $stream = [IO.File]::Open($HostPath, [IO.FileMode]::Open, [IO.FileAccess]::Write, [IO.FileShare]::None)
            try {
                $stream.SetLength(0)
                $stream.Write($bytes, 0, $bytes.Length)
                $stream.Flush($true)
            }
            finally { $stream.Dispose() }
        }
        finally {
            if ($null -ne $bytes) { [Array]::Clear($bytes, 0, $bytes.Length) }
            if ($null -ne $actualHash) { [Array]::Clear($actualHash, 0, $actualHash.Length) }
            if ($null -ne $expectedHash) { [Array]::Clear($expectedHash, 0, $expectedHash.Length) }
        }
        $encoded = ''
        $frameHash = ''
        $frameRun = ''
        $script:wrapperStage = 'credential_copy_final_acl'
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
    }
    catch {
        try { Remove-VerifiedPrivateFile -Path $HostPath -Root $runtimeRoot -Code 'credential_failed_copy_cleanup' } catch {}
        throw
    }
}

function Stop-FqaNativeProcessTree([Diagnostics.Process]$Process) {
    if ($null -eq $Process -or $Process.HasExited) { return $true }
    try {
        $treeKill = [Diagnostics.Process].GetMethod('Kill', [type[]]@([bool]))
        if ($null -ne $treeKill) { [void]$treeKill.Invoke($Process, @($true)) }
        else { $Process.Kill() }
    } catch {}
    try { [void]$Process.WaitForExit(15000) } catch {}
    return $Process.HasExited
}

function Invoke-FqaLeaseRecovery([string]$Run) {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $Run),
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php', $Run
    )
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        try {
            $result = Invoke-BoundedPiwigo -Arguments $tail -TimeoutSeconds $recoveryTimeoutSeconds -Code 'lease_recovery_failed'
            $lines = @(([string]$result.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
            if ($lines.Count -eq 1 -and $lines[0] -eq 'V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked') {
                return $true
            }
        } catch {}
        Start-Sleep -Seconds 2
    }
    return $false
}

function New-FqaLeaseWatchdog([string]$Path) {
    if (Test-Path -LiteralPath $Path) { Stop-V4OwnerFqa 'lease_watchdog_path_not_fresh' }
    Assert-PrivateParentAcl -Candidate $Path -Code 'lease_watchdog'

    # This ignored, owner-only helper contains no credential. It deliberately
    # outlives this wrapper if the wrapper or its WSL broker is terminated. A
    # durable lease may only be recovered after its heartbeat TTL expires, so
    # the helper keeps retrying bounded, authenticated recovery until the
    # maximum browser/broker lifetime plus the lease TTL has elapsed.
    $source = @'
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[a-f0-9]{24}$')]
    [string]$Run,

    [Parameter(Mandatory = $true)]
    [ValidateRange(60, 3600)]
    [int]$LeaseTtlSeconds,

    [Parameter(Mandatory = $true)]
    [ValidateRange(300, 7200)]
    [int]$WatchdogLifetimeSeconds,

    [Parameter(Mandatory = $true)]
    [string]$ProjectRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $ProjectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

$expected = 'V4_OWNER_FQA_LEASE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked'
$deadline = [DateTimeOffset]::UtcNow.AddSeconds($WatchdogLifetimeSeconds)
$recovered = $false
$containerCredentialPath = '/var/lib/class-archive-private-e2e/credentials-' + $Run.Substring(0, 16) + '.json'

function Invoke-WatchdogDocker([string[]]$Tail, [int]$LinuxTimeoutSeconds) {
    $arguments = @(
        '-d', 'Ubuntu', '--cd', $ProjectRoot, '--exec',
        'docker', 'compose',
        '--env-file', 'infra/private-full/.env.piwigo.owner',
        '-f', 'infra/docker-compose.yml',
        '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo'
    ) + $Tail
    $bounded = Add-ClassArchiveWslTimeout -Arguments $arguments -TimeoutSeconds $LinuxTimeoutSeconds
    return Invoke-ClassArchiveBoundedNative `
        -Executable "$env:SystemRoot\System32\wsl.exe" `
        -Arguments $bounded `
        -TimeoutSeconds ($LinuxTimeoutSeconds + 15) `
        -WorkingDirectory $ProjectRoot
}

function Complete-WatchdogCredentialCleanup {
    $container = Invoke-WatchdogDocker -Tail @(
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'rm', '-f', '--', $containerCredentialPath
    ) -LinuxTimeoutSeconds 30
    if ($container.TimedOut -or $container.ExitCode -ne 0) { return $false }

    $hostCredential = Join-Path $PSScriptRoot 'credentials.json'
    if (Test-Path -LiteralPath $hostCredential) {
        $item = Get-Item -LiteralPath $hostCredential -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $false }
        Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
    }
    if (Test-Path -LiteralPath $hostCredential) { return $false }
    [IO.File]::WriteAllText(
        (Join-Path $PSScriptRoot 'WATCHDOG_RECOVERY_COMPLETE'),
        'identity=FROZEN credentials=unknown sessions=revoked',
        [Text.UTF8Encoding]::new($false)
    )
    return $true
}

Start-Sleep -Seconds ($LeaseTtlSeconds + 30)
while ([DateTimeOffset]::UtcNow -lt $deadline) {
    try {
        if (-not $recovered) {
            $result = Invoke-WatchdogDocker -Tail @(
                'exec', '-T', '--user', 'nginx',
                '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
                '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_RECOVERY=1',
                '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $Run),
                'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php', $Run
            ) -LinuxTimeoutSeconds 75
            $lines = @(([string]$result.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
            $recovered = -not $result.TimedOut -and $result.ExitCode -eq 0 -and $lines.Count -eq 1 -and $lines[0] -eq $expected
        }
        if ($recovered -and (Complete-WatchdogCredentialCleanup)) {
            exit 0
        }
    }
    catch {
        # Fail closed and retry. The helper never prints private diagnostics.
    }
    Start-Sleep -Seconds 60
}
exit 2
'@

    $created = [IO.File]::Open($Path, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $created.Dispose()
    try {
        Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
        [IO.File]::WriteAllText($Path, $source, [Text.UTF8Encoding]::new($false))
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    }
    catch {
        try { Remove-VerifiedPrivateFile -Path $Path -Root $runtimeRoot -Code 'lease_watchdog_create_cleanup' } catch {}
        throw
    }
}

function Start-FqaLeaseWatchdog([string]$Path, [string]$Run) {
    New-FqaLeaseWatchdog -Path $Path
    $executable = [Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
    if ([string]::IsNullOrWhiteSpace($executable) -or -not (Test-Path -LiteralPath $executable -PathType Leaf)) {
        Stop-V4OwnerFqa 'lease_watchdog_host_unavailable'
    }
    $lifetime = $leaseTtlSeconds + $browserTimeoutSeconds + $brokerCloseTimeoutSeconds + 600
    $arguments = @(
        '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
        '-File', $Path,
        '-Run', $Run,
        '-LeaseTtlSeconds', [string]$leaseTtlSeconds,
        '-WatchdogLifetimeSeconds', [string]$lifetime,
        '-ProjectRoot', $projectRoot
    )
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $executable
    $info.Arguments = ($arguments | ForEach-Object { ConvertTo-ClassArchiveWin32Argument ([string]$_) }) -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V4OwnerFqa 'lease_watchdog_start_failed' }
    if ($process.WaitForExit(1000)) {
        $process.Dispose()
        Stop-V4OwnerFqa 'lease_watchdog_exited_early'
    }
    return $process
}

function Start-FqaLeaseBroker([string]$Run, [string]$ContainerCredentialPath) {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_LEASE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $Run),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_CREDENTIAL_FILE=' + $ContainerCredentialPath),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS=' + $leaseTtlSeconds),
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php', $Run
    )
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = "$env:SystemRoot\System32\wsl.exe"
    $info.Arguments = ((Get-ComposeArguments $tail) | ForEach-Object { ConvertTo-ClassArchiveWin32Argument ([string]$_) }) -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $info.RedirectStandardInput = $true
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.StandardOutputEncoding = [Text.UTF8Encoding]::new($false)
    $info.StandardErrorEncoding = [Text.UTF8Encoding]::new($false)
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V4OwnerFqa 'lease_broker_start_failed' }
    [void]$process.StandardError.ReadToEndAsync() # drain without reflecting private runtime diagnostics
    $readyTask = $process.StandardOutput.ReadLineAsync()
    if (-not $readyTask.Wait([TimeSpan]::FromSeconds(60))) {
        try { $process.StandardInput.Close() } catch {}
        [void](Stop-FqaNativeProcessTree -Process $process)
        $recovered = Invoke-FqaLeaseRecovery -Run $Run
        $process.Dispose()
        if (-not $recovered) { Stop-V4OwnerFqa 'lease_broker_ready_timeout_recovery_failed' }
        Stop-V4OwnerFqa 'lease_broker_ready_timeout'
    }
    $ready = [string]$readyTask.Result
    if ($ready -ne ('V4_OWNER_FQA_LEASE=READY roles=3 ttl=' + $leaseTtlSeconds)) {
        try { $process.StandardInput.Close() } catch {}
        [void](Stop-FqaNativeProcessTree -Process $process)
        $recovered = Invoke-FqaLeaseRecovery -Run $Run
        $process.Dispose()
        if (-not $recovered) { Stop-V4OwnerFqa 'lease_broker_rejected_recovery_failed' }
        Stop-V4OwnerFqa 'lease_broker_rejected'
    }
    return $process
}

function Close-FqaLeaseBroker([Diagnostics.Process]$Process, [string]$Run) {
    if ($null -eq $Process) { return $false }
    if (-not $Process.HasExited) {
        $Process.StandardInput.WriteLine('STOP ' + $Run)
        $Process.StandardInput.Flush()
        $Process.StandardInput.Close()
    }
    if (-not $Process.WaitForExit($brokerCloseTimeoutSeconds * 1000)) {
        [void](Stop-FqaNativeProcessTree -Process $Process)
    }
    if ($Process.HasExited) {
        $remaining = @($Process.StandardOutput.ReadToEnd() -split "`r?`n" | Where-Object { $_ -ne '' })
        $safe = @($remaining | Where-Object {
            $_ -match '^V4_OWNER_FQA_LEASE=(?:CLOSED identity=FROZEN credentials=unknown sessions=revoked|FAIL stage=(?:bootstrap|runtime) code=[a-z0-9_]+)$'
        })
        # A broker action may fail after the lease opened. It still emits the
        # exact CLOSED record after freeze-first cleanup, while retaining a
        # non-zero exit status and the wrapper's original failure. Treat that
        # record as closure evidence only; otherwise recovery would run after
        # the durable plan has already been securely removed.
        if ($safe.Count -eq 1 -and $safe[0] -eq 'V4_OWNER_FQA_LEASE=CLOSED identity=FROZEN credentials=unknown sessions=revoked') {
            return $true
        }
    }
    # A timeout, abnormal exit, or malformed terminal record is never treated
    # as a successful close. Reap the host process and run the independent,
    # authenticated recovery mode, which freezes first and rotates credentials.
    [void](Stop-FqaNativeProcessTree -Process $Process)
    return Invoke-FqaLeaseRecovery -Run $Run
}

$run = New-RunId
$runRuntime = Join-Path $runtimeRoot $run
$runProfile = Join-Path $profileRoot $run
$runScreenshots = Join-Path $screenshotRoot ('owner-fqa-lease-' + $run)
$credentialPath = Join-Path $runRuntime 'credentials.json'
$watchdogPath = Join-Path $runRuntime 'lease-watchdog.ps1'
$lockPath = Join-Path $runtimeRoot 'owner-fqa-lease.lock'
$containerCredentialPath = '/var/lib/class-archive-private-e2e/credentials-' + $run.Substring(0, 16) + '.json'
$leaseBroker = $null
$leaseWatchdog = $null
$hostLeaseLock = $null
$exitCode = 0
$wrapperStage = 'initialization'
$browserPassRecord = $null
$failureRecord = $null
$cleanupFailed = $false
$cleanupFailureCode = $null
$containerCredentialNeedsCleanup = $false
$leaseMayBeActive = $false
$leaseCloseAttested = $false
$watchdogReaped = $false
$preserveRecoveryRuntime = $false
$oldValues = @{}
$environmentNames = @(
    'NODE_PATH',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT',
    'CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR'
)
foreach ($name in $environmentNames) {
    $item = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -eq $item) { $null } else { [string]$item.Value }
}

try {
    $wrapperStage = 'private_paths'
    foreach ($root in @($runtimeRoot, $profileRoot, $screenshotRoot)) {
        # Validate ignore/boundary and every existing ancestor before creating
        # anything below the private root. A junction cannot redirect the
        # eventual credential or Chrome profile outside the ignored tree.
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Set-OwnerOnlyDirectoryAcl -Path $root
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    Assert-IgnoredPrivateChild -Candidate $lockPath -Root $runtimeRoot -Code 'lease_lock' | Out-Null
    $hostLeaseLock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    foreach ($path in @($runRuntime, $runProfile, $runScreenshots)) {
        Assert-IgnoredPrivateChild -Candidate (Join-Path $path '.path-probe') -Root $path -Code 'run_private_path' | Out-Null
        if (Test-Path -LiteralPath $path) { Stop-V4OwnerFqa 'run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($path)
        Set-OwnerOnlyDirectoryAcl -Path $path
    }
    Assert-IgnoredPrivateChild -Candidate $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $watchdogPath -Root $runtimeRoot -Code 'lease_watchdog' | Out-Null

    $wrapperStage = 'durable_recovery_root'
    Initialize-FqaDurableRecoveryRoot
    $wrapperStage = 'watchdog_start'
    $leaseWatchdog = Start-FqaLeaseWatchdog -Path $watchdogPath -Run $run
    $leaseMayBeActive = $true
    $wrapperStage = 'broker_start'
    $leaseBroker = Start-FqaLeaseBroker -Run $run -ContainerCredentialPath $containerCredentialPath
    $containerCredentialNeedsCleanup = $true
    $wrapperStage = 'credential_copy'
    Copy-FqaCredentialFromBroker -Broker $leaseBroker -Run $run -HostPath $credentialPath
    # Preserve the owner-only recovery plan in the container until exact lease
    # closure. The watchdog uses it after a wrapper/broker crash to remove only
    # password hashes installed by this run.

    $wrapperStage = 'chrome_runner'
    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID = $run
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN = $coreOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT = $runProfile
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR = $runScreenshots

    $nodeResult = Invoke-ClassArchiveBoundedNative `
        -Executable (Get-NodePath) `
        -Arguments @((Join-Path $PSScriptRoot 'photos-app-v4-owner-browser-qa.mjs')) `
        -TimeoutSeconds $browserTimeoutSeconds `
        -WorkingDirectory $projectRoot
    $output = @(([string]$nodeResult.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
    $nodeExit = $nodeResult.ExitCode
    if ($nodeResult.TimedOut) { Stop-V4OwnerFqa 'node_runner_timeout' }
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ roles=3 full_photos=[0-9]+ heritage_photos=[0-9]+ living_photos=[0-9]+ living_scope=(?:present_and_tested|not_present_private_library) channel=chrome chrome_product=chrome chrome_version=[0-9.]+ writes=0$' -or
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS\b' })
    if ($nodeExit -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        Stop-V4OwnerFqa 'node_runner_failed'
    }
    $browserPassRecord = $pass[0]
}
catch {
    try {
        $diagnosticPath = Join-Path $runtimeRoot 'last-failure.local.json'
        $diagnostic = [ordered]@{
            exception_type = $_.Exception.GetType().FullName
            script_line = [int]$_.InvocationInfo.ScriptLineNumber
            offset = [int]$_.InvocationInfo.OffsetInLine
            stack = [string]$_.ScriptStackTrace
            stage = $wrapperStage
        } | ConvertTo-Json -Depth 3
        [IO.File]::WriteAllText($diagnosticPath, $diagnostic, [Text.UTF8Encoding]::new($false))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $diagnosticPath
    } catch {}
    $code = if ($_.Exception.Message -match '^V4_OWNER_FQA_STOP:([A-Za-z0-9_]{1,120})$') {
        [string]$Matches[1]
    } else {
        'unexpected_' + $wrapperStage + '_' + $_.Exception.GetType().Name + '_line' + [string][int]$_.InvocationInfo.ScriptLineNumber
    }
    if ($code -notmatch '^[A-Za-z0-9_]{1,120}$') { $code = 'unexpected' }
    $failureRecord = 'V4_OWNER_FQA_CHROME_QA=FAIL stage=wrapper code=' + $code.ToLowerInvariant()
    $exitCode = 2
}
finally {
    foreach ($name in $environmentNames) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    try {
        if ($null -ne $leaseBroker) {
            $leaseCloseAttested = Close-FqaLeaseBroker -Process $leaseBroker -Run $run
            if ($leaseCloseAttested) { $leaseMayBeActive = $false }
            else { $cleanupFailed = $true; $exitCode = 2 }
        }
        elseif (-not $leaseMayBeActive) {
            $leaseCloseAttested = $true
        }
    } catch {
        $cleanupFailed = $true
        if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'broker_close' }
        $exitCode = 2
    }

    # The independent watchdog is cancelled only after an exact CLOSED or
    # RECOVERED terminal attestation. Otherwise it and its ignored script are
    # deliberately left alive to wait out the durable lease TTL and refreeze.
    if ($leaseCloseAttested) {
        if ($null -eq $leaseWatchdog) {
            $watchdogReaped = $true
        }
        elseif ($leaseWatchdog.HasExited) {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'watchdog_exited' }
            $exitCode = 2
        }
        else {
            $watchdogReaped = Stop-FqaNativeProcessTree -Process $leaseWatchdog
            if (-not $watchdogReaped) {
                $cleanupFailed = $true
                if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'watchdog_reap' }
                $exitCode = 2
            }
        }
    }
    else {
        $preserveRecoveryRuntime = $true
        if ($null -eq $leaseWatchdog -or $leaseWatchdog.HasExited) {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'recovery_watchdog_unavailable' }
            $exitCode = 2
        }
    }
    if ($containerCredentialNeedsCleanup -and $leaseCloseAttested) {
        try {
            [void](Invoke-BoundedPiwigo -Arguments @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $containerCredentialPath) -TimeoutSeconds 30 -Code 'container_credential_cleanup_failed')
            $containerCredentialNeedsCleanup = $false
        } catch {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'container_credential_' + $_.Exception.GetType().Name.ToLowerInvariant() + '_line' + [string][int]$_.InvocationInfo.ScriptLineNumber }
            $exitCode = 2
        }
    }
    if ($leaseCloseAttested) {
        try { Remove-VerifiedPrivateFile -Path $credentialPath -Root $runtimeRoot -Code 'credential_cleanup' }
        catch {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'host_credential' }
            $exitCode = 2
        }
    }
    try { Remove-VerifiedPrivateDirectory -Path $runProfile -Root $profileRoot -Code 'profile_cleanup' }
    catch {
        $cleanupFailed = $true
        if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'chrome_profile' }
        $exitCode = 2
    }
    if (-not $preserveRecoveryRuntime -and $leaseCloseAttested -and $watchdogReaped) {
        try { Remove-VerifiedPrivateDirectory -Path $runRuntime -Root $runtimeRoot -Code 'runtime_cleanup' }
        catch {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'run_runtime' }
            $exitCode = 2
        }
    }
    if ($null -ne $hostLeaseLock) { $hostLeaseLock.Dispose() }
    if ($null -ne $leaseBroker) { $leaseBroker.Dispose() }
    if ($null -ne $leaseWatchdog) { $leaseWatchdog.Dispose() }
}

if ($cleanupFailed) {
    if ($null -ne $failureRecord) { Write-Output $failureRecord }
    if ($null -eq $cleanupFailureCode -or $cleanupFailureCode -notmatch '^[a-z0-9_]{3,96}$') { $cleanupFailureCode = 'unknown' }
    Write-Output ('V4_OWNER_FQA_CLEANUP=FAIL code=' + $cleanupFailureCode)
    Write-Output 'V4_OWNER_FQA_CHROME_QA=FAIL stage=wrapper code=lease_cleanup_failed'
} elseif ($exitCode -eq 0 -and $null -ne $browserPassRecord) {
    Write-Output $browserPassRecord
    Write-Output 'V4_OWNER_FQA_CHROME_QA_COMPLETE=PASS roles=3 identity=FROZEN sessions=revoked credentials=unknown security_lease_writes=audited content_writes=0 teacher=not_tested'
} elseif ($null -ne $failureRecord) {
    Write-Output $failureRecord
} else {
    Write-Output 'V4_OWNER_FQA_CHROME_QA=FAIL stage=wrapper code=completion_record_missing'
    $exitCode = 2
}
exit $exitCode
