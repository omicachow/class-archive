[CmdletBinding()]
param(
    # This is an audited, time-bounded lease of the already provisioned and
    # frozen FQA-T Teacher fixture. It never runs ENSURE: ENSURE is a separate
    # pre-snapshot lifecycle and invoking it here would invalidate the owner
    # acceptance snapshot boundary.
    [switch]$ConfirmTeacherCredentialLease
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not $ConfirmTeacherCredentialLease) {
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=BLOCKED code=explicit_teacher_credential_lease_confirmation_required'
    exit 3
}

# The only intentional runtime identity mutation is the broker's exact
# test-only lease CAS. Normal AdminService mutations are excluded from this
# lease surface and the broker re-freezes/revokes before it emits CLOSED.
$runtimeLeaseMutationExclusionProven = $true
if (-not $runtimeLeaseMutationExclusionProven) {
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=BLOCKED code=lease_runtime_disabled_pending_mutation_exclusion'
    exit 4
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$separator = [IO.Path]::DirectorySeparatorChar
$fixtureRun = '3e2f1a94b0c74d81952e6f0a'
$fixtureTarget = 'PRIVATE_REAL_FULL_OWNER'
$fixtureAck = 'LEASED_TEACHER_FIXTURE_V1'
$coreOrigin = 'http://127.0.0.1:8190/'
$photoOrigin = 'http://127.0.0.1:8191/'
$envRelative = 'infra/private-full/.env.piwigo.owner'
$composeProject = 'class_archive_private_full_v3_piwigo'
$composeFiles = @('infra/docker-compose.yml', 'infra/private-full/docker-compose.override.yml')
$brokerRelative = 'tests/phase3/photos-app-v4-owner-teacher-fixture-broker.php'
$runtimeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner-teacher-lease'))
$profileRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\browser\photos-app-v4-owner-teacher-lease'))
$screenshotRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\screenshots\photos-app-v4'))
$leaseTtlSeconds = 900
$browserTimeoutSeconds = 720
$brokerCloseTimeoutSeconds = 120
$recoveryTimeoutSeconds = 60

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerTeacher([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_TEACHER_STOP:' + $Code)
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
            Stop-V4OwnerTeacher ($Code + '_reparse_ancestor')
        }
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $boundary, [StringComparison]::OrdinalIgnoreCase)) {
            return
        }
        $parent = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
    Stop-V4OwnerTeacher ($Code + '_ancestor_outside_project')
}

function Set-OwnerOnlyDirectoryAcl([string]$Path) {
    $resolved = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).Path
    $item = Get-Item -LiteralPath $resolved -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerTeacher 'private_directory_untrusted'
    }
    try {
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
        return
    } catch {}
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    if ($null -eq $identity) { Stop-V4OwnerTeacher 'private_directory_identity_unavailable' }
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
    $directorySet = [System.IO.Directory].GetMethod(
        'SetAccessControl', [type[]]@([string], [Security.AccessControl.DirectorySecurity])
    )
    if ($null -ne $directorySet) {
        [System.IO.Directory]::SetAccessControl($resolved, $acl)
    } elseif ($null -ne (Get-Command Set-Acl -CommandType Cmdlet -ErrorAction SilentlyContinue)) {
        Set-Acl -LiteralPath $resolved -AclObject $acl
    } else {
        Stop-V4OwnerTeacher 'private_directory_acl_backend_unavailable'
    }
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $resolved
}

function Assert-IgnoredPrivateChild([string]$Candidate, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + $separator
    $rootBoundary = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/') + $separator
    if (-not $full.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerTeacher ($Code + '_outside_project')
    }
    if (-not $full.StartsWith($rootBoundary, [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4OwnerTeacher ($Code + '_outside_root')
    }
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0) { Stop-V4OwnerTeacher ($Code + '_not_ignored') }
    if (@(& git -C $projectRoot ls-files -- $relative).Count -ne 0) {
        Stop-V4OwnerTeacher ($Code + '_tracked')
    }
    Assert-NoReparseAncestor -Candidate $full -Code $Code
    return $full
}

function Assert-PrivateParentAcl([string]$Candidate, [string]$Code) {
    Assert-NoReparseAncestor -Candidate $Candidate -Code $Code
    $parent = [IO.Path]::GetDirectoryName([IO.Path]::GetFullPath($Candidate))
    if ([string]::IsNullOrWhiteSpace($parent) -or -not (Test-Path -LiteralPath $parent -PathType Container)) {
        Stop-V4OwnerTeacher ($Code + '_parent_unavailable')
    }
    $trustedRoot = $runtimeRoot.TrimEnd('\', '/')
    $trustedBoundary = $trustedRoot + $separator
    $cursor = [IO.Path]::GetFullPath($parent).TrimEnd('\', '/')
    if (-not ([string]::Equals($cursor, $trustedRoot, [StringComparison]::OrdinalIgnoreCase)) -and -not ($cursor.StartsWith($trustedBoundary, [StringComparison]::OrdinalIgnoreCase))) {
        Stop-V4OwnerTeacher ($Code + '_parent_outside_private_root')
    }
    while ($true) {
        $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
        if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
            Stop-V4OwnerTeacher ($Code + '_parent_reparse')
        }
        try { Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName }
        catch { Stop-V4OwnerTeacher ($Code + '_parent_acl_invalid') }
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $trustedRoot, [StringComparison]::OrdinalIgnoreCase)) {
            return
        }
        $next = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $next) { Stop-V4OwnerTeacher ($Code + '_parent_chain_invalid') }
        $cursor = $next.FullName.TrimEnd('\', '/')
    }
}

function Remove-VerifiedPrivateFile([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerTeacher ($Code + '_untrusted')
    }
    Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code | Out-Null
    Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
}

function Remove-VerifiedPrivateDirectory([string]$Path, [string]$Root, [string]$Code) {
    if (-not (Test-Path -LiteralPath $Path)) { return }
    $item = Get-Item -LiteralPath $Path -Force -ErrorAction Stop
    if (-not $item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        Stop-V4OwnerTeacher ($Code + '_untrusted')
    }
    $full = Assert-IgnoredPrivateChild -Candidate $item.FullName -Root $Root -Code $Code
    $reparse = @(Get-ChildItem -LiteralPath $full -Force -Recurse -ErrorAction Stop | Where-Object {
        $_.Attributes -band [IO.FileAttributes]::ReparsePoint
    })
    if ($reparse.Count -ne 0) { Stop-V4OwnerTeacher ($Code + '_contains_reparse') }
    Remove-Item -LiteralPath $full -Recurse -Force -ErrorAction Stop
}

function New-AttemptId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Get-NodePath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $node = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
    if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { Stop-V4OwnerTeacher 'node_unavailable' }
    return $node
}

function Get-NodeModulesPath {
    $userProfile = [Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)
    $modules = Join-Path $userProfile '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\node_modules'
    if (-not (Test-Path -LiteralPath $modules -PathType Container)) { Stop-V4OwnerTeacher 'node_modules_unavailable' }
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

function Invoke-BoundedOwnerPiwigo([string[]]$Arguments, [int]$TimeoutSeconds, [string]$Code) {
    $wslArguments = Add-ClassArchiveWslTimeout -Arguments (Get-ComposeArguments $Arguments) -TimeoutSeconds $TimeoutSeconds
    $result = Invoke-ClassArchiveBoundedNative `
        -Executable "$env:SystemRoot\System32\wsl.exe" `
        -Arguments $wslArguments `
        -TimeoutSeconds ($TimeoutSeconds + 15) `
        -WorkingDirectory $projectRoot
    if ($result.TimedOut -or $result.ExitCode -ne 0) { Stop-V4OwnerTeacher $Code }
    return $result
}

function Initialize-TeacherDurableRecoveryRoot {
    $root = '/var/lib/class-archive-private-e2e'
    $script = 'mountpoint -q -- ' + $root +
        ' && install -d -o nginx -g nginx -m 0700 -- ' + $root +
        ' && test "$(stat -c %U:%G:%a -- ' + $root + ')" = "nginx:nginx:700"'
    [void](Invoke-BoundedOwnerPiwigo -Arguments @(
        'exec', '-T', '--user', 'root', 'piwigo', 'sh', '-ec', $script
    ) -TimeoutSeconds 30 -Code 'durable_recovery_root_unavailable')
}

function ConvertFrom-TeacherBase64Url([string]$Value) {
    if ($Value -notmatch '^[A-Za-z0-9_-]{2,131072}$') { Stop-V4OwnerTeacher 'credential_transport_invalid' }
    $standard = $Value.Replace('-', '+').Replace('_', '/')
    switch ($standard.Length % 4) {
        0 { }
        2 { $standard += '==' }
        3 { $standard += '=' }
        default { Stop-V4OwnerTeacher 'credential_transport_invalid' }
    }
    try { return ,([Convert]::FromBase64String($standard)) }
    catch { Stop-V4OwnerTeacher 'credential_transport_invalid' }
    finally { $standard = '' }
}

function Assert-TeacherBrowserCredentialDocument([byte[]]$Bytes) {
    if ($null -eq $Bytes -or $Bytes.Length -lt 128 -or $Bytes.Length -gt 65536) {
        Stop-V4OwnerTeacher 'credential_size_invalid'
    }
    $documentText = ''
    try {
        try { $documentText = [Text.UTF8Encoding]::new($false, $true).GetString($Bytes) }
        catch { Stop-V4OwnerTeacher 'credential_document_invalid' }
        if ($documentText.IndexOf([char]0) -ge 0) { Stop-V4OwnerTeacher 'credential_document_invalid' }
        try { $document = $documentText | ConvertFrom-Json -ErrorAction Stop }
        catch { Stop-V4OwnerTeacher 'credential_document_invalid' }
        if ($null -eq $document) { Stop-V4OwnerTeacher 'credential_document_invalid' }
        $rootKeys = @($document.PSObject.Properties.Name | Sort-Object)
        $documentVersion = [int]$document.version
        $documentEnvironment = [string]$document.environment
        $documentRun = [string]$document.run
        if ((($rootKeys -join ',') -ne 'environment,lease,roles,run,version') -or
            ($documentVersion -ne 1) -or
            ($documentEnvironment -ne 'PRIVATE_REAL_FULL_OWNER_V4_TEACHER_BROWSER_EXPORT') -or
            ($documentRun -ne $fixtureRun)) {
            Stop-V4OwnerTeacher 'credential_document_scope'
        }
        if ($null -eq $document.lease -or $null -eq $document.roles) {
            Stop-V4OwnerTeacher 'credential_document_scope'
        }
        $leaseKeys = @($document.lease.PSObject.Properties.Name | Sort-Object)
        $leaseRole = [string]$document.lease.role
        $leaseRoster = [string]$document.lease.roster
        if ((($leaseKeys -join ',') -ne 'role,roster') -or
            ($leaseRole -ne 'TEACHER') -or
            ($leaseRoster -ne ('FQA-T-' + $fixtureRun.ToUpperInvariant()))) {
            Stop-V4OwnerTeacher 'credential_lease_scope'
        }
        $roleKeys = @($document.roles.PSObject.Properties.Name | Sort-Object)
        $credential = $document.roles.teacher
        if ($null -eq $credential) { Stop-V4OwnerTeacher 'credential_teacher_invalid' }
        $credentialKeys = @($credential.PSObject.Properties.Name | Sort-Object)
        $username = [string]$credential.username
        $password = [string]$credential.password
        if ((($roleKeys -join ',') -ne 'teacher') -or
            (($credentialKeys -join ',') -ne 'password,username') -or
            ($username -ne ('fqa_t_' + $fixtureRun + '_teacher')) -or
            ($password -notmatch '^[A-Za-z0-9_-]{64}$')) {
            Stop-V4OwnerTeacher 'credential_teacher_invalid'
        }
    }
    finally {
        $document = $null
        $credential = $null
        $password = ''
        $documentText = ''
    }
}

function Write-TeacherBrokerControl([Diagnostics.Process]$Broker, [string]$Command, [switch]$CloseAfter) {
    if ($null -eq $Broker -or $Broker.HasExited) { throw [InvalidOperationException]::new('broker_control_unavailable') }
    if ($Command -notmatch ('^(?:EXPORT|STOP) ' + $fixtureRun + '$')) { throw [InvalidOperationException]::new('broker_control_invalid') }
    $bytes = $null
    try {
        # Never use StreamWriter here: PowerShell 5.1 can emit a UTF-8 BOM to
        # the broker control pipe before the strict EXPORT/STOP token.
        $bytes = [Text.UTF8Encoding]::new($false).GetBytes($Command + "`n")
        $stream = $Broker.StandardInput.BaseStream
        $stream.Write($bytes, 0, $bytes.Length)
        $stream.Flush()
        if ($CloseAfter.IsPresent) { $stream.Close() }
    }
    finally { if ($null -ne $bytes) { [Array]::Clear($bytes, 0, $bytes.Length) } }
}

function Copy-TeacherCredentialFromBroker([Diagnostics.Process]$Broker, [string]$HostPath) {
    $script:wrapperStage = 'credential_copy_preflight'
    if ($null -eq $Broker -or $Broker.HasExited) { Stop-V4OwnerTeacher 'credential_broker_unavailable' }
    if (Test-Path -LiteralPath $HostPath) { Stop-V4OwnerTeacher 'credential_path_not_fresh' }
    Assert-PrivateParentAcl -Candidate $HostPath -Code 'credential'
    $created = [IO.File]::Open($HostPath, [IO.FileMode]::CreateNew, [IO.FileAccess]::Write, [IO.FileShare]::None)
    $created.Dispose()
    try {
        Set-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
        $script:wrapperStage = 'credential_broker_export'
        try { Write-TeacherBrokerControl -Broker $Broker -Command ('EXPORT ' + $fixtureRun) }
        catch { Stop-V4OwnerTeacher 'credential_broker_unavailable' }
        $exportTask = $Broker.StandardOutput.ReadLineAsync()
        if (-not $exportTask.Wait([TimeSpan]::FromSeconds(30))) { Stop-V4OwnerTeacher 'credential_export_timeout' }
        $record = [string]$exportTask.Result
        $frame = [regex]::Match($record, '^V4_OWNER_TEACHER_FIXTURE_CREDENTIAL=([A-Za-z0-9_-]{2,131072})$')
        if (-not $frame.Success) {
            $failure = [regex]::Match($record, '^V4_OWNER_TEACHER_FIXTURE=FAIL stage=(?:bootstrap|runtime) code=([a-z0-9_]{1,96})$')
            if ($failure.Success) { Stop-V4OwnerTeacher ('credential_export_rejected_' + [string]$failure.Groups[1].Value) }
            Stop-V4OwnerTeacher 'credential_export_invalid'
        }
        $encoded = [string]$frame.Groups[1].Value
        $record = ''
        $frame = $null
        $bytes = $null
        try {
            $script:wrapperStage = 'credential_copy_decode'
            $bytes = ConvertFrom-TeacherBase64Url -Value $encoded
            Assert-TeacherBrowserCredentialDocument -Bytes $bytes
            $script:wrapperStage = 'credential_copy_write'
            $stream = [IO.File]::Open($HostPath, [IO.FileMode]::Open, [IO.FileAccess]::Write, [IO.FileShare]::None)
            try {
                $stream.SetLength(0)
                $stream.Write($bytes, 0, $bytes.Length)
                $stream.Flush($true)
            } finally { $stream.Dispose() }
        }
        finally { if ($null -ne $bytes) { [Array]::Clear($bytes, 0, $bytes.Length) } }
        $encoded = ''
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $HostPath
    }
    catch {
        try { Remove-VerifiedPrivateFile -Path $HostPath -Root $runtimeRoot -Code 'credential_failed_copy_cleanup' } catch {}
        throw
    }
}

function Stop-TeacherNativeProcessTree([Diagnostics.Process]$Process) {
    if ($null -eq $Process -or $Process.HasExited) { return $true }
    try {
        $treeKill = [Diagnostics.Process].GetMethod('Kill', [type[]]@([bool]))
        if ($null -ne $treeKill) { [void]$treeKill.Invoke($Process, @($true)) } else { $Process.Kill() }
    } catch {}
    try { [void]$Process.WaitForExit(15000) } catch {}
    return $Process.HasExited
}

function Invoke-TeacherLeaseRecovery {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=' + $fixtureTarget),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' + $fixtureAck),
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + $fixtureRun),
        'piwigo', 'php', ('/workspace/' + $brokerRelative.Replace('\\', '/')), $fixtureRun
    )
    for ($attempt = 0; $attempt -lt 3; $attempt++) {
        try {
            $result = Invoke-BoundedOwnerPiwigo -Arguments $tail -TimeoutSeconds $recoveryTimeoutSeconds -Code 'lease_recovery_failed'
            $lines = @(([string]$result.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
            if ($lines.Count -eq 1 -and $lines[0] -eq 'V4_OWNER_TEACHER_FIXTURE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked') { return $true }
        } catch {}
        Start-Sleep -Seconds 2
    }
    return $false
}

function New-TeacherLeaseWatchdog([string]$Path, [string]$ProfilePath, [string]$ProfileRoot) {
    if (Test-Path -LiteralPath $Path) { Stop-V4OwnerTeacher 'lease_watchdog_path_not_fresh' }
    Assert-PrivateParentAcl -Candidate $Path -Code 'lease_watchdog'
    $source = @'
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)][ValidatePattern('^[a-f0-9]{24}$')][string]$Run,
    [Parameter(Mandatory = $true)][ValidateRange(60, 3600)][int]$LeaseTtlSeconds,
    [Parameter(Mandatory = $true)][ValidateRange(300, 7200)][int]$WatchdogLifetimeSeconds,
    [Parameter(Mandatory = $true)][string]$ProjectRoot,
    [Parameter(Mandatory = $true)][string]$ProfilePath,
    [Parameter(Mandatory = $true)][string]$ProfileRoot
)
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
. (Join-Path $ProjectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')
. (Join-Path $ProjectRoot 'infra\scripts\secret-file-acl.ps1')
$expected = 'V4_OWNER_TEACHER_FIXTURE=RECOVERED identity=FROZEN credentials=unknown sessions=revoked'
$deadline = [DateTimeOffset]::UtcNow.AddSeconds($WatchdogLifetimeSeconds)
$recovered = $false
function Invoke-WatchdogDocker([string[]]$Tail, [int]$LinuxTimeoutSeconds) {
    $arguments = @('-d', 'Ubuntu', '--cd', $ProjectRoot, '--exec', 'docker', 'compose', '--env-file', 'infra/private-full/.env.piwigo.owner', '-f', 'infra/docker-compose.yml', '-f', 'infra/private-full/docker-compose.override.yml', '-p', 'class_archive_private_full_v3_piwigo') + $Tail
    $bounded = Add-ClassArchiveWslTimeout -Arguments $arguments -TimeoutSeconds $LinuxTimeoutSeconds
    return Invoke-ClassArchiveBoundedNative -Executable "$env:SystemRoot\System32\wsl.exe" -Arguments $bounded -TimeoutSeconds ($LinuxTimeoutSeconds + 15) -WorkingDirectory $ProjectRoot
}
function Remove-WatchdogPrivateProfile {
    try {
        $rootFull = [IO.Path]::GetFullPath($ProfileRoot).TrimEnd('\\', '/')
        $profileFull = [IO.Path]::GetFullPath($ProfilePath).TrimEnd('\\', '/')
        $separator = [IO.Path]::DirectorySeparatorChar
        if ([string]::IsNullOrWhiteSpace($rootFull) -or [string]::IsNullOrWhiteSpace($profileFull)) { return $false }
        if (-not $profileFull.StartsWith($rootFull + $separator, [StringComparison]::OrdinalIgnoreCase)) { return $false }
        if (-not (Test-Path -LiteralPath $profileFull)) { return $true }
        $root = Get-Item -LiteralPath $rootFull -Force -ErrorAction Stop
        $profile = Get-Item -LiteralPath $profileFull -Force -ErrorAction Stop
        if (-not $root.PSIsContainer -or -not $profile.PSIsContainer -or ($root.Attributes -band [IO.FileAttributes]::ReparsePoint) -or ($profile.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $false }
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $root.FullName
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $profile.FullName
        $pending = [Collections.Generic.Stack[string]]::new()
        $pending.Push($profile.FullName)
        while ($pending.Count -gt 0) {
            $current = $pending.Pop()
            foreach ($child in @(Get-ChildItem -LiteralPath $current -Force -ErrorAction Stop)) {
                if ($child.Attributes -band [IO.FileAttributes]::ReparsePoint) { return $false }
                if ($child.PSIsContainer) { $pending.Push($child.FullName) }
            }
        }
        Remove-Item -LiteralPath $profile.FullName -Recurse -Force -ErrorAction Stop
        return -not (Test-Path -LiteralPath $profile.FullName)
    } catch {
        return $false
    }
}
function Complete-WatchdogCredentialCleanup {
    $hostCredential = Join-Path $PSScriptRoot 'credentials.json'
    if (Test-Path -LiteralPath $hostCredential) {
        $item = Get-Item -LiteralPath $hostCredential -Force -ErrorAction Stop
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { return $false }
        Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName
        Remove-Item -LiteralPath $item.FullName -Force -ErrorAction Stop
    }
    if (Test-Path -LiteralPath $hostCredential) { return $false }
    if (-not (Remove-WatchdogPrivateProfile)) { return $false }
    $marker = Join-Path $PSScriptRoot 'WATCHDOG_RECOVERY_COMPLETE'
    [IO.File]::WriteAllText($marker, 'identity=FROZEN credentials=unknown sessions=revoked', [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $marker
    return $true
}
Start-Sleep -Seconds ($LeaseTtlSeconds + 30)
while ([DateTimeOffset]::UtcNow -lt $deadline) {
    try {
        if (-not $recovered) {
            $result = Invoke-WatchdogDocker -Tail @('exec', '-T', '--user', 'nginx', '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1', '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=PRIVATE_REAL_FULL_OWNER', '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=LEASED_TEACHER_FIXTURE_V1', '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RECOVERY=1', '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + $Run), 'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-teacher-fixture-broker.php', $Run) -LinuxTimeoutSeconds 75
            $lines = @(([string]$result.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
            $recovered = -not $result.TimedOut -and $result.ExitCode -eq 0 -and $lines.Count -eq 1 -and $lines[0] -eq $expected
        }
        if ($recovered -and (Complete-WatchdogCredentialCleanup)) { exit 0 }
    } catch {
        # Fail closed and retry. No private diagnostics or credential bytes are printed.
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

function Start-TeacherLeaseWatchdog([string]$Path) {
    New-TeacherLeaseWatchdog -Path $Path -ProfilePath $runProfile -ProfileRoot $profileRoot
    $executable = [Diagnostics.Process]::GetCurrentProcess().MainModule.FileName
    if ([string]::IsNullOrWhiteSpace($executable) -or -not (Test-Path -LiteralPath $executable -PathType Leaf)) {
        Stop-V4OwnerTeacher 'lease_watchdog_host_unavailable'
    }
    $lifetime = $leaseTtlSeconds + $browserTimeoutSeconds + $brokerCloseTimeoutSeconds + 600
    $arguments = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $Path, '-Run', $fixtureRun, '-LeaseTtlSeconds', [string]$leaseTtlSeconds, '-WatchdogLifetimeSeconds', [string]$lifetime, '-ProjectRoot', $projectRoot, '-ProfilePath', $runProfile, '-ProfileRoot', $profileRoot)
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = $executable
    $info.Arguments = ($arguments | ForEach-Object { ConvertTo-ClassArchiveWin32Argument ([string]$_) }) -join ' '
    $info.WorkingDirectory = $projectRoot
    $info.UseShellExecute = $false
    $info.CreateNoWindow = $true
    $process = [Diagnostics.Process]::new()
    $process.StartInfo = $info
    if (-not $process.Start()) { Stop-V4OwnerTeacher 'lease_watchdog_start_failed' }
    if ($process.WaitForExit(1000)) { $process.Dispose(); Stop-V4OwnerTeacher 'lease_watchdog_exited_early' }
    return $process
}

function Start-TeacherLeaseBroker {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_PRIVATE_E2E_ENABLED=1',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TARGET=' + $fixtureTarget),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_ACK=' + $fixtureAck),
        '-e', 'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_LEASE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID=' + $fixtureRun),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_TTL_SECONDS=' + $leaseTtlSeconds),
        'piwigo', 'php', ('/workspace/' + $brokerRelative.Replace('\\', '/')), $fixtureRun
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
    $originalConsoleInputEncoding = $null
    $consoleInputEncodingSet = $false
    $brokerStarted = $false
    $consoleRestoreFailed = $false
    try {
        $originalConsoleInputEncoding = [Console]::InputEncoding
        [Console]::InputEncoding = [Text.UTF8Encoding]::new($false)
        $consoleInputEncodingSet = $true
        $brokerStarted = $process.Start()
    }
    catch {}
    finally {
        if ($consoleInputEncodingSet) {
            try { [Console]::InputEncoding = $originalConsoleInputEncoding }
            catch { $consoleRestoreFailed = $true }
        }
    }
    if (-not $brokerStarted -or $consoleRestoreFailed) {
        [void](Stop-TeacherNativeProcessTree -Process $process)
        $recovered = Invoke-TeacherLeaseRecovery
        $process.Dispose()
        if (-not $recovered) { Stop-V4OwnerTeacher 'lease_broker_start_recovery_failed' }
        Stop-V4OwnerTeacher 'lease_broker_start_failed_recovered'
    }
    [void]$process.StandardError.ReadToEndAsync() # drained and never reflected into stdout
    $readyTask = $process.StandardOutput.ReadLineAsync()
    if (-not $readyTask.Wait([TimeSpan]::FromSeconds(60))) {
        try { $process.StandardInput.Close() } catch {}
        [void](Stop-TeacherNativeProcessTree -Process $process)
        $recovered = Invoke-TeacherLeaseRecovery
        $process.Dispose()
        if (-not $recovered) { Stop-V4OwnerTeacher 'lease_broker_ready_timeout_recovery_failed' }
        Stop-V4OwnerTeacher 'lease_broker_ready_timeout'
    }
    $ready = [string]$readyTask.Result
    if ($ready -ne ('V4_OWNER_TEACHER_FIXTURE=READY roles=1 ttl=' + $leaseTtlSeconds)) {
        try { $process.StandardInput.Close() } catch {}
        [void](Stop-TeacherNativeProcessTree -Process $process)
        $recovered = Invoke-TeacherLeaseRecovery
        $process.Dispose()
        if (-not $recovered) { Stop-V4OwnerTeacher 'lease_broker_rejected_recovery_failed' }
        Stop-V4OwnerTeacher 'lease_broker_rejected'
    }
    return $process
}

function Close-TeacherLeaseBroker([Diagnostics.Process]$Process) {
    if ($null -eq $Process) { return $false }
    if (-not $Process.HasExited) {
        try { Write-TeacherBrokerControl -Broker $Process -Command ('STOP ' + $fixtureRun) -CloseAfter }
        catch { return $false }
    }
    if (-not $Process.WaitForExit($brokerCloseTimeoutSeconds * 1000)) { [void](Stop-TeacherNativeProcessTree -Process $Process) }
    if ($Process.HasExited) {
        $remaining = @($Process.StandardOutput.ReadToEnd() -split "`r?`n" | Where-Object { $_ -ne '' })
        if ($remaining.Count -eq 1 -and $remaining[0] -eq 'V4_OWNER_TEACHER_FIXTURE=CLOSED identity=FROZEN credentials=unknown sessions=revoked') { return $true }
    }
    [void](Stop-TeacherNativeProcessTree -Process $Process)
    return Invoke-TeacherLeaseRecovery
}

$attempt = New-AttemptId
$runRuntime = Join-Path $runtimeRoot $attempt
$runProfile = Join-Path $profileRoot $attempt
$runScreenshots = Join-Path $screenshotRoot ('owner-teacher-lease-' + $attempt)
$credentialPath = Join-Path $runRuntime 'credentials.json'
$watchdogPath = Join-Path $runRuntime 'lease-watchdog.ps1'
$lockPath = Join-Path $runtimeRoot 'owner-teacher-lease.lock'
$leaseBroker = $null
$leaseWatchdog = $null
$hostLeaseLock = $null
$exitCode = 0
$wrapperStage = 'initialization'
$browserPassRecord = $null
$failureRecord = $null
$cleanupFailed = $false
$cleanupFailureCode = $null
$watchdogStarted = $false
$leaseMayBeActive = $false
$leaseCloseAttested = $false
$watchdogReaped = $false
$preserveRecoveryRuntime = $false
$oldValues = @{}
$environmentNames = @(
    'NODE_PATH',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CORE_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PHOTO_ORIGIN',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CREDENTIAL_FILE',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PROFILE_ROOT',
    'CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_SCREENSHOT_DIR'
)
foreach ($name in $environmentNames) {
    $item = Get-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
    $oldValues[$name] = if ($null -eq $item) { $null } else { [string]$item.Value }
}

try {
    $wrapperStage = 'private_paths'
    foreach ($root in @($runtimeRoot, $profileRoot, $screenshotRoot)) {
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Set-OwnerOnlyDirectoryAcl -Path $root
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    Assert-IgnoredPrivateChild -Candidate $lockPath -Root $runtimeRoot -Code 'lease_lock' | Out-Null
    $hostLeaseLock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    foreach ($path in @($runRuntime, $runProfile, $runScreenshots)) {
        Assert-IgnoredPrivateChild -Candidate (Join-Path $path '.path-probe') -Root $path -Code 'run_private_path' | Out-Null
        if (Test-Path -LiteralPath $path) { Stop-V4OwnerTeacher 'run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($path)
        Set-OwnerOnlyDirectoryAcl -Path $path
    }
    Assert-IgnoredPrivateChild -Candidate $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null
    Assert-IgnoredPrivateChild -Candidate $watchdogPath -Root $runtimeRoot -Code 'lease_watchdog' | Out-Null

    $wrapperStage = 'durable_recovery_root'
    Initialize-TeacherDurableRecoveryRoot
    $wrapperStage = 'watchdog_start'
    $leaseWatchdog = Start-TeacherLeaseWatchdog -Path $watchdogPath
    $watchdogStarted = $true
    $wrapperStage = 'broker_start'
    $leaseBroker = Start-TeacherLeaseBroker
    # A watchdog process alone does not imply that the broker ever opened an
    # identity lease. Only the exact READY-returning broker path reaches this
    # assignment; earlier failures can be reaped and cleaned immediately.
    $leaseMayBeActive = $true
    $wrapperStage = 'credential_copy'
    Copy-TeacherCredentialFromBroker -Broker $leaseBroker -HostPath $credentialPath

    $wrapperStage = 'chrome_runner'
    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_RUN_ID = $fixtureRun
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CORE_ORIGIN = $coreOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_PROFILE_ROOT = $runProfile
    $env:CLASS_ARCHIVE_V4_OWNER_TEACHER_FIXTURE_SCREENSHOT_DIR = $runScreenshots
    $nodeResult = Invoke-ClassArchiveBoundedNative `
        -Executable (Get-NodePath) `
        -Arguments @((Join-Path $PSScriptRoot 'photos-app-v4-owner-teacher-browser-qa.mjs')) `
        -TimeoutSeconds $browserTimeoutSeconds `
        -WorkingDirectory $projectRoot
    $output = @(([string]$nodeResult.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
    if ($nodeResult.TimedOut) { Stop-V4OwnerTeacher 'node_runner_timeout' }
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_OWNER_TEACHER_FIXTURE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_OWNER_TEACHER_FIXTURE_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ role=TEACHER channel=chrome chrome_product=chrome chrome_version=[0-9.]+ browse=home_library_albums_people_search_viewer media=mediaguard_api_paths writes=0$' -or
        $_ -match '^V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=[a-z0-9_]+$'
    })
    $pass = @($safe | Where-Object { $_ -match '^V4_OWNER_TEACHER_FIXTURE_CHROME_QA=PASS\b' })
    if ($nodeResult.ExitCode -ne 0 -or $pass.Count -ne 1) {
        $failure = @($safe | Where-Object { $_ -match '^V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL\b' } | Select-Object -Last 1)
        if ($failure.Count -eq 1) { Write-Output $failure[0] }
        Stop-V4OwnerTeacher 'node_runner_failed'
    }
    $browserPassRecord = $pass[0]
}
catch {
    # Start-TeacherLeaseBroker performs a synchronous broker RECOVERY for the
    # only paths that can have opened a lease before returning. If that
    # recovery itself fails, retain the watchdog/runtime rather than treating
    # the lease as absent and deleting its only recovery material.
    if ($_.Exception.Message -match '^V4_OWNER_TEACHER_STOP:lease_broker_(?:ready_timeout|rejected)_recovery_failed$') {
        $leaseMayBeActive = $true
    }
    try {
        $diagnosticPath = Join-Path $runtimeRoot 'last-failure.local.json'
        $diagnostic = [ordered]@{
            exception_type = $_.Exception.GetType().FullName
            script_line = [int]$_.InvocationInfo.ScriptLineNumber
            stage = $wrapperStage
        } | ConvertTo-Json -Depth 2
        [IO.File]::WriteAllText($diagnosticPath, $diagnostic, [Text.UTF8Encoding]::new($false))
        Set-ClassArchiveOwnerOnlyFileAcl -Path $diagnosticPath
    } catch {}
    $code = if ($_.Exception.Message -match '^V4_OWNER_TEACHER_STOP:([A-Za-z0-9_]{1,120})$') { [string]$Matches[1] } else { 'unexpected' }
    $failureRecord = 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL stage=wrapper code=' + $code.ToLowerInvariant()
    $exitCode = 2
}
finally {
    foreach ($name in $environmentNames) {
        Remove-Item -LiteralPath ("Env:$name") -ErrorAction SilentlyContinue
        if ($null -ne $oldValues[$name]) { Set-Item -LiteralPath ("Env:$name") -Value $oldValues[$name] }
    }
    try {
        if ($null -ne $leaseBroker) {
            $leaseCloseAttested = Close-TeacherLeaseBroker -Process $leaseBroker
            if ($leaseCloseAttested) { $leaseMayBeActive = $false } else { $cleanupFailed = $true; $exitCode = 2 }
        } elseif (-not $leaseMayBeActive) { $leaseCloseAttested = $true }
    } catch {
        $cleanupFailed = $true
        if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'broker_close' }
        $exitCode = 2
    }
    if ($leaseCloseAttested) {
        if (-not $watchdogStarted -or $null -eq $leaseWatchdog) { $watchdogReaped = $true }
        elseif ($leaseWatchdog.HasExited) {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'watchdog_exited' }
            $exitCode = 2
        } else {
            $watchdogReaped = Stop-TeacherNativeProcessTree -Process $leaseWatchdog
            if (-not $watchdogReaped) {
                $cleanupFailed = $true
                if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'watchdog_reap' }
                $exitCode = 2
            }
        }
    } else {
        $preserveRecoveryRuntime = $true
        if ($null -eq $leaseWatchdog -or $leaseWatchdog.HasExited) {
            $cleanupFailed = $true
            if ($null -eq $cleanupFailureCode) { $cleanupFailureCode = 'recovery_watchdog_unavailable' }
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
    Write-Output ('V4_OWNER_TEACHER_FIXTURE_CLEANUP=FAIL code=' + $cleanupFailureCode)
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL stage=wrapper code=lease_cleanup_failed'
} elseif ($exitCode -eq 0 -and $null -ne $browserPassRecord) {
    Write-Output $browserPassRecord
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA_COMPLETE=PASS roles=1 identity=FROZEN sessions=revoked credentials=unknown security_lease_writes=audited content_writes=0 fixture=FQA-T'
} elseif ($null -ne $failureRecord) {
    Write-Output $failureRecord
} else {
    Write-Output 'V4_OWNER_TEACHER_FIXTURE_CHROME_QA=FAIL stage=wrapper code=completion_record_missing'
    $exitCode = 2
}
exit $exitCode
