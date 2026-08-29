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

# The broker protocol is complete, but the ordinary AdminService mutation path
# does not yet participate in its advisory lock. Until that exclusion becomes
# a production invariant, even an explicitly confirmed run remains disabled:
# an out-of-band unfreeze could race the final verifier and leave access open.
$runtimeLeaseMutationExclusionProven = $false
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

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerFqa([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_FQA_STOP:' + $Code)
}

function Assert-NoReparseAncestor([string]$Candidate, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $boundary = $projectRoot.TrimEnd('\', '/')
    $cursor = if (Test-Path -LiteralPath $full) { $full } else { [IO.Path]::GetDirectoryName($full) }
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
    Set-Acl -LiteralPath $resolved -AclObject $acl
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

function Invoke-Piwigo([string[]]$Arguments, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& "$env:SystemRoot\System32\wsl.exe" @(Get-ComposeArguments $Arguments) 2>&1)
        $exit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exit -ne 0) { Stop-V4OwnerFqa $Code }
    return [string]::Join("`n", [string[]]@($lines | ForEach-Object { [string]$_ }))
}

function Start-FqaLeaseBroker([string]$Run, [string]$ContainerCredentialPath) {
    $tail = @(
        'exec', '-T', '--user', 'nginx',
        '-e', 'CLASS_ARCHIVE_V4_OWNER_FQA_LEASE=1',
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_RUN_ID=' + $Run),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_CREDENTIAL_FILE=' + $ContainerCredentialPath),
        '-e', ('CLASS_ARCHIVE_V4_OWNER_FQA_TTL_SECONDS=' + $leaseTtlSeconds),
        'piwigo', 'php', '/workspace/tests/phase3/photos-app-v4-owner-fqa-lease.php'
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
        try { [void]$process.WaitForExit(120000) } catch {}
        $process.Dispose()
        Stop-V4OwnerFqa 'lease_broker_ready_timeout'
    }
    $ready = [string]$readyTask.Result
    if ($ready -ne ('V4_OWNER_FQA_LEASE=READY roles=3 ttl=' + $leaseTtlSeconds)) {
        try { $process.StandardInput.Close() } catch {}
        try { [void]$process.WaitForExit(120000) } catch {}
        $process.Dispose()
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
    if (-not $Process.WaitForExit(120000)) {
        return $false # broker TTL remains the independent fail-safe
    }
    $remaining = @($Process.StandardOutput.ReadToEnd() -split "`r?`n" | Where-Object { $_ -ne '' })
    $safe = @($remaining | Where-Object {
        $_ -match '^V4_OWNER_FQA_LEASE=(?:CLOSED identity=FROZEN credentials=unknown sessions=revoked|FAIL stage=(?:bootstrap|runtime) code=[a-z0-9_]+)$'
    })
    return $Process.ExitCode -eq 0 -and $safe.Count -eq 1 -and $safe[0] -eq 'V4_OWNER_FQA_LEASE=CLOSED identity=FROZEN credentials=unknown sessions=revoked'
}

$run = New-RunId
$runRuntime = Join-Path $runtimeRoot $run
$runProfile = Join-Path $profileRoot $run
$runScreenshots = Join-Path $screenshotRoot ('owner-fqa-lease-' + $run)
$credentialPath = Join-Path $runRuntime 'credentials.json'
$lockPath = Join-Path $runtimeRoot 'owner-fqa-lease.lock'
$containerCredentialPath = '/tmp/class-archive-v4-fqa-credentials-' + $run.Substring(0, 16) + '.json'
$leaseBroker = $null
$hostLeaseLock = $null
$exitCode = 0
$wrapperStage = 'initialization'
$browserPassRecord = $null
$failureRecord = $null
$cleanupFailed = $false
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
        if (-not (Test-Path -LiteralPath $root)) { [void][IO.Directory]::CreateDirectory($root) }
        Set-OwnerOnlyDirectoryAcl -Path $root
        Assert-IgnoredPrivateChild -Candidate (Join-Path $root '.path-probe') -Root $root -Code 'private_root' | Out-Null
    }
    Assert-IgnoredPrivateChild -Candidate $lockPath -Root $runtimeRoot -Code 'lease_lock' | Out-Null
    $hostLeaseLock = [IO.File]::Open($lockPath, [IO.FileMode]::OpenOrCreate, [IO.FileAccess]::ReadWrite, [IO.FileShare]::None)
    foreach ($path in @($runRuntime, $runProfile, $runScreenshots)) {
        if (Test-Path -LiteralPath $path) { Stop-V4OwnerFqa 'run_path_not_fresh' }
        [void][IO.Directory]::CreateDirectory($path)
        Set-OwnerOnlyDirectoryAcl -Path $path
    }
    Assert-IgnoredPrivateChild -Candidate $credentialPath -Root $runtimeRoot -Code 'credential' | Out-Null

    $wrapperStage = 'lease_open'
    $leaseBroker = Start-FqaLeaseBroker -Run $run -ContainerCredentialPath $containerCredentialPath
    $relative = $credentialPath.Substring($projectRoot.Length + 1).Replace('\', '/')
    [void](Invoke-Piwigo -Arguments @('cp', ('piwigo:' + $containerCredentialPath), $relative) -Code 'credential_copy_failed')
    Set-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath
    [void](Invoke-Piwigo -Arguments @('exec', '-T', '--user', 'nginx', 'piwigo', 'rm', '-f', '--', $containerCredentialPath) -Code 'container_credential_cleanup_failed')

    $wrapperStage = 'chrome_runner'
    $env:NODE_PATH = Get-NodeModulesPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_RUN_ID = $run
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CORE_ORIGIN = $coreOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PHOTO_ORIGIN = $photoOrigin
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_CREDENTIAL_FILE = $credentialPath
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_PROFILE_ROOT = $runProfile
    $env:CLASS_ARCHIVE_V4_OWNER_FIXTURE_SCREENSHOT_DIR = $runScreenshots

    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& (Get-NodePath) (Join-Path $PSScriptRoot 'photos-app-v4-owner-browser-qa.mjs') 2>&1)
        $nodeExit = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    $safe = @($output | ForEach-Object { [string]$_ } | Where-Object {
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_STAGE=[a-z0-9_-]+$' -or
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ roles=3 full_photos=[0-9]+ heritage_photos=[0-9]+ living_photos=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+ writes=0$' -or
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
    $code = if ($_.Exception.Message -match '^V4_OWNER_FQA_STOP:([A-Za-z0-9_]{1,120})$') {
        [string]$Matches[1]
    } else {
        'unexpected_' + $wrapperStage + '_' + $_.Exception.GetType().Name
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
        if ($null -ne $leaseBroker -and -not (Close-FqaLeaseBroker -Process $leaseBroker -Run $run)) { $cleanupFailed = $true; $exitCode = 2 }
    } catch { $cleanupFailed = $true; $exitCode = 2 }
    try { Remove-VerifiedPrivateFile -Path $credentialPath -Root $runtimeRoot -Code 'credential_cleanup' } catch { $cleanupFailed = $true; $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runProfile -Root $profileRoot -Code 'profile_cleanup' } catch { $cleanupFailed = $true; $exitCode = 2 }
    try { Remove-VerifiedPrivateDirectory -Path $runRuntime -Root $runtimeRoot -Code 'runtime_cleanup' } catch { $cleanupFailed = $true; $exitCode = 2 }
    if ($null -ne $hostLeaseLock) { $hostLeaseLock.Dispose() }
    if ($null -ne $leaseBroker) { $leaseBroker.Dispose() }
}

if ($exitCode -eq 0) {
    Write-Output $browserPassRecord
    Write-Output 'V4_OWNER_FQA_CHROME_QA_COMPLETE=PASS roles=3 identity=FROZEN sessions=revoked credentials=unknown security_lease_writes=audited content_writes=0 teacher=not_tested'
} elseif ($null -ne $failureRecord) {
    Write-Output $failureRecord
} elseif ($cleanupFailed) {
    Write-Output 'V4_OWNER_FQA_CHROME_QA=FAIL stage=wrapper code=lease_cleanup_failed'
}
exit $exitCode
