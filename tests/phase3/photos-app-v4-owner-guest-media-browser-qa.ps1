[CmdletBinding()]
param(
    # Runs two headed, fresh-profile Chrome proofs against localhost only:
    # FQA Classmate produces an opaque BFF media document, then an unauthenticated
    # Guest proves that GET/HEAD/Range are denied. No credential reaches Guest.
    [switch]$ConfirmOwnerGuestMediaAcceptance
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

if (-not $ConfirmOwnerGuestMediaAcceptance) {
    Write-Output 'V4_OWNER_GUEST_MEDIA_CHROME_QA=BLOCKED code=explicit_owner_guest_media_confirmation_required'
    exit 3
}

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$separator = [IO.Path]::DirectorySeparatorChar
$probeRoot = [IO.Path]::GetFullPath((Join-Path $projectRoot '.codex-work\private-real-qa\runtime\photos-app-v4-owner-guest\opaque-media-probes'))
$fqaWrapper = Join-Path $PSScriptRoot 'photos-app-v4-owner-browser-qa.ps1'
$guestWrapper = Join-Path $PSScriptRoot 'photos-app-v4-owner-guest-browser-qa.ps1'
$success = $false
$resultCode = 'unexpected_wrapper_failure'
$probePath = $null

. (Join-Path $projectRoot 'infra\scripts\secret-file-acl.ps1')
. (Join-Path $projectRoot 'infra\scripts\class-archive-bounded-native-process.ps1')

function Stop-V4OwnerGuestMedia([string]$Code) {
    throw [InvalidOperationException]::new('V4_OWNER_GUEST_MEDIA_STOP:' + $Code)
}

function Assert-V4OwnerGuestMedia([bool]$Condition, [string]$Code) {
    if (-not $Condition) { Stop-V4OwnerGuestMedia $Code }
}

function New-V4OwnerGuestMediaRunId {
    $bytes = New-Object byte[] 12
    $rng = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $rng.GetBytes($bytes) } finally { $rng.Dispose() }
    return (($bytes | ForEach-Object { $_.ToString('x2') }) -join '')
}

function Assert-V4OwnerGuestMediaNoReparseAncestor([string]$Candidate, [string]$Code) {
    $cursor = [IO.Path]::GetFullPath($Candidate)
    $boundary = $projectRoot.TrimEnd('\', '/')
    while (-not [string]::IsNullOrWhiteSpace($cursor) -and -not (Test-Path -LiteralPath $cursor)) {
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
    while (-not [string]::IsNullOrWhiteSpace($cursor)) {
        $item = Get-Item -LiteralPath $cursor -Force -ErrorAction Stop
        Assert-V4OwnerGuestMedia (-not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) ($Code + '_reparse_ancestor')
        if ([string]::Equals($item.FullName.TrimEnd('\', '/'), $boundary, [StringComparison]::OrdinalIgnoreCase)) { return }
        $parent = [IO.Directory]::GetParent($item.FullName)
        if ($null -eq $parent) { break }
        $cursor = $parent.FullName
    }
    Stop-V4OwnerGuestMedia ($Code + '_ancestor_outside_project')
}

function Assert-V4OwnerGuestMediaIgnored([string]$Candidate, [string]$Root, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Candidate)
    $projectBoundary = $projectRoot.TrimEnd('\', '/') + $separator
    $rootFull = [IO.Path]::GetFullPath($Root).TrimEnd('\', '/')
    $rootBoundary = $rootFull + $separator
    Assert-V4OwnerGuestMedia ($full.StartsWith($projectBoundary, [StringComparison]::OrdinalIgnoreCase)) ($Code + '_outside_project')
    Assert-V4OwnerGuestMedia ($full.StartsWith($rootBoundary, [StringComparison]::OrdinalIgnoreCase)) ($Code + '_outside_root')
    $relative = $full.Substring($projectRoot.Length + 1).Replace('\', '/')
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    Assert-V4OwnerGuestMedia ($LASTEXITCODE -eq 0) ($Code + '_not_ignored')
    Assert-V4OwnerGuestMedia (@(& git -C $projectRoot ls-files -- $relative 2>$null).Count -eq 0) ($Code + '_tracked')
    Assert-V4OwnerGuestMediaNoReparseAncestor -Candidate $full -Code $Code
    return $full
}

function Assert-V4OwnerGuestMediaProbe([string]$Path, [switch]$MustExist) {
    $full = Assert-V4OwnerGuestMediaIgnored -Candidate $Path -Root $probeRoot -Code 'media_probe_document'
    Assert-V4OwnerGuestMedia ([IO.Path]::GetFileName($full) -match '^owner-fqa-[a-f0-9]{24}\.json$') 'media_probe_document_name'
    if ($MustExist) {
        $item = Get-Item -LiteralPath $full -Force -ErrorAction Stop
        Assert-V4OwnerGuestMedia (-not $item.PSIsContainer -and -not ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) 'media_probe_document_untrusted'
        try { Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName }
        catch { Stop-V4OwnerGuestMedia 'media_probe_document_acl_invalid' }
        Assert-V4OwnerGuestMedia ($item.Length -ge 96 -and $item.Length -le 4096) 'media_probe_document_size'
    }
    return $full
}

function Invoke-V4OwnerGuestMediaWrapper([string]$ScriptPath, [string[]]$Arguments, [int]$TimeoutSeconds, [string]$Code) {
    $shell = (Get-Command powershell.exe -CommandType Application -ErrorAction Stop).Source
    $result = Invoke-ClassArchiveBoundedNative -Executable $shell `
        -Arguments (@('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $ScriptPath) + $Arguments) `
        -TimeoutSeconds $TimeoutSeconds -WorkingDirectory $projectRoot
    Assert-V4OwnerGuestMedia (-not $result.TimedOut) ($Code + '_timeout')
    return $result
}

function Remove-V4OwnerGuestMediaProbe([string]$Path) {
    if ([string]::IsNullOrWhiteSpace($Path) -or -not (Test-Path -LiteralPath $Path)) { return }
    $full = Assert-V4OwnerGuestMediaProbe -Path $Path -MustExist
    Remove-Item -LiteralPath $full -Force -ErrorAction Stop
    Assert-V4OwnerGuestMedia (-not (Test-Path -LiteralPath $full)) 'media_probe_document_cleanup_remains'
}

try {
    Assert-V4OwnerGuestMedia (Test-Path -LiteralPath $fqaWrapper -PathType Leaf) 'fqa_wrapper_missing'
    Assert-V4OwnerGuestMedia (Test-Path -LiteralPath $guestWrapper -PathType Leaf) 'guest_wrapper_missing'
    $probePath = Join-Path $probeRoot ('owner-fqa-' + (New-V4OwnerGuestMediaRunId) + '.json')
    $probePath = Assert-V4OwnerGuestMediaProbe -Path $probePath
    Assert-V4OwnerGuestMedia (-not (Test-Path -LiteralPath $probePath)) 'media_probe_document_not_fresh'

    $fqa = Invoke-V4OwnerGuestMediaWrapper -ScriptPath $fqaWrapper `
        -Arguments @('-ConfirmFqaCredentialLease', '-GuestMediaProbeDocument', $probePath) `
        -TimeoutSeconds 900 -Code 'fqa'
    $fqaLines = @(([string]$fqa.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
    $fqaPass = @($fqaLines | Where-Object {
        $_ -match '^V4_OWNER_EXISTING_FIXTURE_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ roles=3 full_photos=[0-9]+ heritage_photos=[0-9]+ living_photos=[0-9]+ living_scope=(?:present_and_tested|not_present_private_library) channel=chrome chrome_product=chrome chrome_version=[0-9.]+ writes=0$'
    })
    $fqaClose = @($fqaLines | Where-Object {
        $_ -eq 'V4_OWNER_FQA_CHROME_QA_COMPLETE=PASS roles=3 identity=FROZEN sessions=revoked credentials=unknown security_lease_writes=audited content_writes=0 teacher=not_tested'
    })
    if ($fqa.ExitCode -ne 0 -or $fqaPass.Count -ne 1 -or $fqaClose.Count -ne 1) {
        $failure = @($fqaLines | Where-Object { $_ -match '^V4_OWNER_FQA_CHROME_QA=FAIL stage=[a-z0-9_-]+ code=([a-z0-9_]{1,100})$' } | Select-Object -Last 1)
        if ($failure.Count -eq 1 -and $failure[0] -match 'code=([a-z0-9_]{1,80})$') { Stop-V4OwnerGuestMedia ('fqa_' + [string]$Matches[1]) }
        Stop-V4OwnerGuestMedia 'fqa_result_invalid'
    }
    [void](Assert-V4OwnerGuestMediaProbe -Path $probePath -MustExist)

    $guest = Invoke-V4OwnerGuestMediaWrapper -ScriptPath $guestWrapper `
        -Arguments @('-ConfirmGuestReadOnlyAcceptance', '-MediaProbeDocument', $probePath) `
        -TimeoutSeconds 240 -Code 'guest'
    $guestLines = @(([string]$guest.Stdout) -split "`r?`n" | Where-Object { $_ -ne '' })
    $guestPass = @($guestLines | Where-Object { $_ -eq 'V4_OWNER_GUEST_CHROME_QA=PASS' })
    if ($guest.ExitCode -ne 0 -or $guestPass.Count -ne 1) {
        $failure = @($guestLines | Where-Object { $_ -match '^V4_OWNER_GUEST_CHROME_QA=FAIL code=([a-z0-9_]{1,100})$' } | Select-Object -Last 1)
        if ($failure.Count -eq 1 -and $failure[0] -match 'code=([a-z0-9_]{1,80})$') { Stop-V4OwnerGuestMedia ('guest_' + [string]$Matches[1]) }
        Stop-V4OwnerGuestMedia 'guest_result_invalid'
    }
    Assert-V4OwnerGuestMedia (-not (Test-Path -LiteralPath $probePath)) 'guest_media_probe_not_consumed'
    $success = $true
}
catch {
    $resultCode = if ($_.Exception.Message -match '^V4_OWNER_GUEST_MEDIA_STOP:([a-z0-9_]{1,100})$') {
        [string]$Matches[1]
    } else {
        'unexpected_wrapper_failure'
    }
}
finally {
    $cleanupFailed = $false
    try { Remove-V4OwnerGuestMediaProbe -Path $probePath }
    catch { $cleanupFailed = $true }
    if ($cleanupFailed) {
        $success = $false
        if ($resultCode -eq 'unexpected_wrapper_failure') { $resultCode = 'media_probe_document_cleanup_failed' }
    }
}

if ($success) {
    Write-Output 'V4_OWNER_GUEST_MEDIA_CHROME_QA=PASS fqa=PASS guest=PASS media=GET_HEAD_RANGE'
    exit 0
}
Write-Output ('V4_OWNER_GUEST_MEDIA_CHROME_QA=FAIL code=' + $resultCode)
exit 2
