[CmdletBinding()]
param(
    # This writes a private-owner attestation record after exercising the
    # actual 8190 library. It is intentionally not a default action and never
    # accepts a staging/synthetic endpoint selector.
    [switch]$ConfirmOwnerPrivateAttestation
)

# Private-full MediaGuard attestation. The ordinary Phase 0 suite is still the
# complete synthetic role/era regression matrix; this owner-only runner binds
# that regression result to a clean commit and additionally proves that the
# active full real-library runtime denies a guest direct access to one actual
# managed original and derivative. It emits only compact aggregate records.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$privateLifecycle = Join-Path $PSScriptRoot 'private-full.ps1'
$phase0Runner = Join-Path $PSScriptRoot 'dev.ps1'
$ownerMediaRuntime = Join-Path $projectRoot 'tests\phase3\private-full-media-runtime.ps1'
$ownerMediaHttp = Join-Path $projectRoot 'tests\phase3\private-full-owner-media-http.ps1'
$ownerOperationsProtocol = Join-Path $projectRoot 'tests\phase3\private-full-owner-operations-protocol.ps1'
$writeAttestation = '/workspace/infra/scripts/write-media-attestation.php'
$wsl = "$env:SystemRoot\System32\wsl.exe"
$assertions = 0

function Stop-PrivateFullOwnerAttestation([string]$Code) {
    throw [InvalidOperationException]::new('PRIVATE_FULL_OWNER_ATTEST_STOP:' + $Code)
}

function Assert-PrivateFullOwnerAttestation([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { Stop-PrivateFullOwnerAttestation $Code }
}

function Invoke-CapturedOwnerAttestationCommand([scriptblock]$Command, [string]$Code) {
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $output = @(& $Command 2>&1)
        $exitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previous
    }
    if ($exitCode -ne 0) { Stop-PrivateFullOwnerAttestation $Code }
    return @($output | ForEach-Object { [string]$_ })
}

function Get-OnlySafeRecord([string[]]$Lines, [string]$Pattern, [string]$Code) {
    $safe = @($Lines | Where-Object { $_ -match $Pattern })
    Assert-PrivateFullOwnerAttestation ($safe.Count -eq 1) $Code
    return [string]$safe[0]
}

try {
    Assert-PrivateFullOwnerAttestation $ConfirmOwnerPrivateAttestation.IsPresent 'owner_attestation_confirmation_required'
    foreach ($required in @($wsl, $privateLifecycle, $phase0Runner, $ownerMediaRuntime, $ownerMediaHttp, $ownerOperationsProtocol)) {
        Assert-PrivateFullOwnerAttestation (Test-Path -LiteralPath $required -PathType Leaf) 'attestation_runner_missing'
    }

    # The attestation is commit-bound. A dirty checkout could otherwise hash
    # different bytes from those supplied to the owner container, so refuse it
    # before any test or persistent record write.
    $dirty = @(& git -C $projectRoot status --porcelain 2>$null)
    Assert-PrivateFullOwnerAttestation ($LASTEXITCODE -eq 0 -and $dirty.Count -eq 0) 'attestation_requires_clean_checkout'
    $commit = (& git -C $projectRoot rev-parse HEAD).Trim()
    Assert-PrivateFullOwnerAttestation ($LASTEXITCODE -eq 0 -and $commit -match '^[0-9a-f]{40}$') 'attestation_commit_unavailable'

    # Keep the owner command wiring itself under a static, public-safe gate
    # before it is allowed to create a persistent owner attestation record.
    $operationsProtocolOutput = Invoke-CapturedOwnerAttestationCommand {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ownerOperationsProtocol
    } 'owner_operations_protocol_failed'
    [void](Get-OnlySafeRecord $operationsProtocolOutput '^PRIVATE_FULL_OWNER_OPERATIONS_PROTOCOL=PASS assertions=\d+$' 'owner_operations_protocol_record_invalid')

    # Phase 0 is the role/era truth. Keep its full output private to this
    # process and retain only the total HTTP probe count for the owner record.
    $phase0Output = Invoke-CapturedOwnerAttestationCommand {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $phase0Runner test-phase0
    } 'synthetic_phase0_failed'
    $probeCount = 0
    foreach ($line in $phase0Output) {
        $match = [regex]::Match($line, 'HTTP_PROBES=(\d+)')
        if ($match.Success) { $probeCount += [int]$match.Groups[1].Value }
    }
    Assert-PrivateFullOwnerAttestation ($probeCount -gt 0) 'synthetic_phase0_probe_count_missing'

    # Prove that the selected 8190/8191 process tree is the owner endpoint;
    # private-full.ps1 validates ignored owner env files, loopback binds,
    # volumes, BFF boundary and the exact normal HTTP response.
    $runtimeOutput = Invoke-CapturedOwnerAttestationCommand {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $privateLifecycle runtime-owner
    } 'owner_runtime_boundary_failed'
    [void](Get-OnlySafeRecord $runtimeOutput '^PRIVATE_FULL=PASS action=runtime-owner endpoint=8190_8191 evidence=RUNTIME_BOUNDARY_VALIDATED core_http=READY\b' 'owner_runtime_record_invalid')

    # This verifier reads only aggregate private-full managed-original state;
    # it performs no source/staging/manifest lookup and exposes no filenames,
    # ids, hashes or paths.
    $mediaRuntimeOutput = Invoke-CapturedOwnerAttestationCommand {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ownerMediaRuntime -Mode owner
    } 'owner_media_runtime_failed'
    [void](Get-OnlySafeRecord $mediaRuntimeOutput '^PRIVATE_FULL_MEDIA_RUNTIME=PASS assertions=\d+ originals=\d+ mode_0660_verified=\d+ checksum_sampled=\d+ managed_reference_mode=CANONICAL_ONLY wrapper_assertions=\d+$' 'owner_media_runtime_record_invalid')

    # A second owner-only fixture derives one actual managed source and one
    # derivative inside Piwigo, then sends unauthenticated GET/HEAD/Range only
    # to nginx loopback. It does not consume a response body, preventing the
    # attestation process from ever streaming a private image.
    $mediaHttpOutput = Invoke-CapturedOwnerAttestationCommand {
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $ownerMediaHttp
    } 'owner_media_http_failed'
    $mediaHttpRecord = Get-OnlySafeRecord $mediaHttpOutput '^PRIVATE_FULL_OWNER_MEDIA_HTTP=PASS assertions=\d+ direct_guest_requests=6 methods=GET_HEAD_RANGE surfaces=ORIGINAL_DERIVATIVE scope=OWNER_8190 wrapper_assertions=\d+$' 'owner_media_http_record_invalid'

    # Tests must not leave an uncommitted source edit between phase0 and record
    # creation. This is deliberately stricter than an attested-path check.
    $dirtyAfter = @(& git -C $projectRoot status --porcelain 2>$null)
    Assert-PrivateFullOwnerAttestation ($LASTEXITCODE -eq 0 -and $dirtyAfter.Count -eq 0) 'attestation_checkout_changed'
    $commitAfter = (& git -C $projectRoot rev-parse HEAD).Trim()
    Assert-PrivateFullOwnerAttestation ($LASTEXITCODE -eq 0 -and $commitAfter -eq $commit) 'attestation_commit_changed'

    $compose = @(
        '-d', 'Ubuntu', '--cd', $projectRoot, '--exec', 'docker', 'compose',
        '--env-file', 'infra/private-full/.env.piwigo.owner',
        '-f', 'infra/docker-compose.yml',
        '-f', 'infra/private-full/docker-compose.override.yml',
        '-p', 'class_archive_private_full_v3_piwigo',
        'exec', '-T', '--user', 'nginx', 'piwigo',
        'php', $writeAttestation,
        ('--commit=' + $commit),
        ('--probe-count=' + $probeCount),
        '--test-suite-version=private-full-owner-media-guard-v1'
    )
    $writeOutput = Invoke-CapturedOwnerAttestationCommand {
        & $wsl @compose
    } 'owner_attestation_write_failed'
    [void](Get-OnlySafeRecord $writeOutput ('^MEDIA_ATTESTATION=PASS commit=' + [regex]::Escape($commit) + ' probes=' + $probeCount + '$') 'owner_attestation_write_record_invalid')

    # The synthetic proof is never presented as owner evidence: the final
    # grammar expressly includes both the isolated owner scope and the two
    # owner runtime fixtures which made this record eligible.
    Write-Output ('PRIVATE_FULL_OWNER_MEDIA_ATTESTATION=PASS commit=' + $commit + ' synthetic_phase0_probes=' + $probeCount + ' owner_http=GET_HEAD_RANGE_DENY owner_runtime=VERIFIED scope=OWNER_8190_8191 assertions=' + $assertions)
}
catch {
    $code = if ($_.Exception.Message -match '^PRIVATE_FULL_OWNER_ATTEST_STOP:([a-z0-9_]{1,96})$') {
        [string]$Matches[1]
    } else {
        'unexpected_attestation_failure'
    }
    Write-Output ('PRIVATE_FULL_OWNER_MEDIA_ATTESTATION=FAIL code=' + $code + ' assertions=' + $assertions)
    exit 2
}
