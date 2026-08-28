[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('Record', 'Verify')]
    [string]$Action,

    # A leaf token keeps the durable gate independent of a private host path.
    # It is written only below the ignored .codex-work root.
    [string]$GateName,

    # Record reads bounded, owner-local command transcripts. Verify deliberately
    # does not need their paths and relies on the immutable digest record.
    [string]$EvidenceDirectory
)

# Immutable Phase A/B acceptance attestation for the V4 owner migration gate.
#
# This is not an authorization mechanism and it does not start Docker, launch
# Chrome, touch an Owner database, or inspect private media. It converts only
# already-produced Synthetic 8091 acceptance transcripts into a narrow local
# attestation. The private V17 -> V18 adapter verifies this attestation before
# acquiring its maintenance lock, so a migration cannot silently skip Phase A
# Chrome/MediaGuard evidence or Phase B cold-restart evidence.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attestationRoot = Join-Path $projectRoot '.codex-work\v4-synthetic-acceptance'
. (Join-Path $PSScriptRoot 'secret-file-acl.ps1')

function Stop-V4SyntheticAcceptance([string]$Code) {
    throw [InvalidOperationException]::new('V4_SYNTHETIC_ACCEPTANCE_STOP:' + $Code)
}

function Get-ProjectRelativePath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $prefix = $projectRoot.TrimEnd('\', '/') + [IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) { Stop-V4SyntheticAcceptance 'path_outside_checkout' }
    return $full.Substring($prefix.Length).Replace('\', '/')
}

function Assert-IgnoredDirectory([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $root = [IO.Path]::GetFullPath($attestationRoot).TrimEnd('\')
    if (-not [string]::Equals($full.TrimEnd('\'), $root, [StringComparison]::OrdinalIgnoreCase) -and
        -not $full.StartsWith($root + '\', [StringComparison]::OrdinalIgnoreCase)) {
        Stop-V4SyntheticAcceptance 'private_root_required'
    }
    [void][IO.Directory]::CreateDirectory($full)
    $item = Get-Item -LiteralPath $full -Force
    if (-not $item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0)) { Stop-V4SyntheticAcceptance 'private_directory_untrusted' }
    $relative = Get-ProjectRelativePath $item.FullName
    & git -C $projectRoot check-ignore --quiet --no-index -- $relative
    if ($LASTEXITCODE -ne 0 -or @(& git -C $projectRoot ls-files -- $relative 2>$null).Count -ne 0) { Stop-V4SyntheticAcceptance 'private_directory_not_ignored' }
    return $item.FullName
}

function Assert-PlainPrivateFile([string]$Path, [string]$Code) {
    $full = [IO.Path]::GetFullPath($Path)
    $parent = Split-Path -Parent $full
    [void](Assert-IgnoredDirectory $parent)
    if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { Stop-V4SyntheticAcceptance $Code }
    $item = Get-Item -LiteralPath $full -Force
    if ($item.PSIsContainer -or (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) -or $item.Length -gt 262144) { Stop-V4SyntheticAcceptance $Code }
    return $item.FullName
}

function Get-Property([object]$Object, [string]$Name) {
    if ($null -eq $Object) { return $null }
    $property = $Object.PSObject.Properties[$Name]
    if ($null -eq $property) { return $null }
    return $property.Value
}

function Get-CurrentHead {
    $lines = @(& git -C $projectRoot rev-parse HEAD 2>$null)
    if ($LASTEXITCODE -ne 0 -or $lines.Count -ne 1 -or ([string]$lines[0]).Trim() -notmatch '^[a-f0-9]{40}$') { Stop-V4SyntheticAcceptance 'git_head_invalid' }
    return ([string]$lines[0]).Trim()
}

function Assert-CleanAcceptanceCheckout {
    # A transcript is evidence for the code which actually ran, not merely for
    # the most recent committed revision.  Refuse both Record and Verify on a
    # dirty checkout so an uncommitted policy/test change cannot be exercised,
    # reset, and then paired with stale evidence from the same HEAD.
    $previous = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        $lines = @(& git -C $projectRoot status --porcelain=v1 --untracked-files=all 2>$null)
        $exitCode = $LASTEXITCODE
    }
    finally { $ErrorActionPreference = $previous }
    if ($exitCode -ne 0) { Stop-V4SyntheticAcceptance 'acceptance_checkout_status_invalid' }
    if (@($lines | Where-Object { -not [string]::IsNullOrWhiteSpace([string]$_) }).Count -ne 0) {
        Stop-V4SyntheticAcceptance 'acceptance_checkout_not_clean'
    }
}

function Get-SourceDigests {
    $relativePaths = @(
        # Direct host-side prerequisites.  The attester and cold-restart flow
        # dot-source these helpers, so their behavior is evidence-critical.
        'infra/scripts/secret-file-acl.ps1',
        'infra/scripts/configure-piwigo-baseline.php',
        'infra/scripts/rebuild-photo-read-projection.php',
        'infra/docker-compose.yml',
        'infra/immich-spike/docker-compose.yml',
        'tests/support/system-admin-session.ps1',
        'plugins/ClassIdentity/src/Schema.php',
        # Bind the complete server-side authorization and projection chain,
        # not just the browser runners.  A Phase A/B record must cease to
        # apply if a policy, upload, comment, or MediaGuard path changes.
        'plugins/ClassIdentity/public.php',
        'plugins/ClassIdentity/src/AlbumService.php',
        'plugins/ClassIdentity/src/AnonymousPresenter.php',
        'plugins/ClassIdentity/src/CapabilityGuard.php',
        'plugins/ClassIdentity/src/MemberEraUploadService.php',
        'plugins/ClassIdentity/src/PhotoCommentService.php',
        'plugins/ClassIdentity/src/SpotlightRotationService.php',
        'plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
        'plugins/ClassIdentity/src/Gateway/GatewayPolicy.php',
        'plugins/ClassIdentity/src/Gateway/GatewayService.php',
        'plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php',
        'plugins/ClassIdentity/src/Gateway/ReadProjectionBuilder.php',
        'plugins/ClassIdentity/src/Gateway/CollectionSnapshotBuilder.php',
        'plugins/ClassArchivePolicy/main.inc.php',
        'plugins/ClassArchivePolicy/media-gateway.php',
        'plugins/ClassArchivePolicy/src/MediaGuard.php',
        'plugins/ClassArchivePolicy/src/MediaFilePolicy.php',
        'infra/piwigo-nginx/nginx.conf',
        'infra/immich-spike/photo-ui/index.html',
        'infra/immich-spike/photo-ui/app.js',
        'infra/immich-spike/photo-ui/app.css',
        'infra/immich-spike/photo-ui/i18n.js',
        'infra/immich-spike/photo-ui/ui-dom.js',
        'infra/immich-spike/photo-ui/ui-era-upload.js',
        'infra/immich-spike/photo-ui/ui-search-overlay.js',
        'infra/immich-spike/web-compat/server.mjs',
        'tests/phase3/photos-app-v4-viewer-fixture.php',
        'tests/phase3/photos-app-v4-upload-lifecycle-fixture.php',
        'tests/phase3/photos-app-v4-scope-unknown-fixture.php',
        'tests/phase3/photos-app-v4-chrome-qa.mjs',
        'tests/phase3/photos-app-v4-chrome-deep-qa.mjs',
        'tests/phase3/photos-app-v4-chrome-scope-projection.mjs',
        'tests/phase3/photos-app-v4-chrome-upload-lifecycle.mjs',
        'tests/phase3/photos-app-v4-chrome-localhost-guard.mjs',
        'tests/phase3/photos-app-v4-chrome-qa.ps1',
        'tests/phase3/photos-app-v4-chrome-deep-qa.ps1',
        'tests/phase3/photos-app-v4-chrome-scope-projection.ps1',
        'tests/phase3/photos-app-v4-chrome-upload-lifecycle.ps1',
        # Bind the static contracts that accept the four browser transcripts;
        # otherwise a changed transcript validator could be paired with old
        # browser evidence under the same source-digest claim.
        'tests/phase3/photos-app-v4-chrome-qa-protocol.ps1',
        'tests/phase3/photos-app-v4-chrome-deep-qa-protocol.ps1',
        'tests/phase3/photos-app-v4-chrome-scope-projection-protocol.ps1',
        'tests/phase3/photos-app-v4-chrome-upload-lifecycle-protocol.ps1',
        'tests/phase3/photos-app-v4-chrome-localhost-guard-protocol.ps1',
        'tests/phase3/member-era-upload-contract.mjs',
        'tests/phase3/photos-app-v4-contract.mjs',
        'tests/phase3/photos-app-v4-ui-contract.mjs',
        'tests/phase3/search-context-contract.mjs',
        # The accepted MediaGuard regression transcript is produced by these
        # public synthetic Phase 0/1 runners and their fixture cleanup paths.
        'tests/phase0/assert-photo-model.php',
        'tests/phase0/assert-media-permissions.sh',
        'tests/phase0/smoke-photo-ui.ps1',
        'tests/phase0/access-matrix.ps1',
        'tests/phase0/media-guard-http.ps1',
        'tests/phase0/media-guard-tiny-preview.ps1',
        'tests/phase0/media-guard-state-transitions.ps1',
        'tests/phase1/class-plugin-workflow-lock.ps1',
        'tests/class-identity-maintenance-protocol.php',
        'tests/phase1/media-file-policy.php',
        'tests/class-identity-enforcement-context.php',
        'tests/class-identity-anonymous-presenter.php',
        'tests/class-identity-audit-reason.php',
        'tests/class-identity-capability-guard.php',
        'tests/class-identity-rate-limiter.php',
        'tests/class-identity-schema-semantics.php',
        'tests/class-identity-synthetic-bootstrap-protocol.php',
        'tests/system-admin-credential-protocol.php',
        'tests/system-admin-session-fault-http.ps1',
        'tests/phase1/class-identity-http.ps1',
        'tests/phase1/maintenance-gate-http.ps1',
        'tests/phase1/runtime-surface-http.ps1',
        'tests/phase1/enforcement-fault-http.ps1',
        'tests/phase1/capability-guard-http.ps1',
        'tests/phase1/pending-media-http.ps1',
        'tests/phase1/anonymous-presenter-http.ps1',
        'tests/phase1/class-identity-fixture.php',
        'tests/phase1/pending-media-fixture.php',
        'tests/phase1/enforcement-fault-fixture.php',
        'tests/phase1/anonymous-presenter-fixture.php',
        # Every accepted browser/restart transcript is serialized by this
        # ignored owner-only host lease. Its source and static contract must
        # invalidate evidence when the lease behavior changes.
        'infra/scripts/v4-synthetic-phase-a-lease.ps1',
        'tests/phase3/v4-synthetic-phase-a-lease-protocol.ps1',
        # The non-empty People prerequisite temporarily mutates synthetic
        # projection rows before delegating the scope browser evidence. Bind
        # its fixture, lifecycle, static contract, and operator-facing
        # boundary documentation to the same attestation source revision.
        'docs/photos-app-v4-scope-people-lifecycle.md',
        'tests/phase3/photos-app-v4-scope-people-fixture.php',
        'tests/phase3/photos-app-v4-scope-people-lifecycle.ps1',
        'tests/phase3/photos-app-v4-scope-people-lifecycle-protocol.ps1',
        # Phase B proves persistence against the same projection/runtime and
        # cold-restart scripts. Any change here invalidates old restart
        # evidence instead of allowing it to be paired with a new migration.
        'tests/phase3/read-projection-runtime.ps1',
        'tests/phase3/read-projection-runtime-snapshot.php',
        'tests/phase3/read-projection-runtime-fixture.php',
        'tests/phase3/photos-app-v4-synthetic-cold-restart-snapshot.php',
        'tests/phase3/photos-app-v4-synthetic-cold-restart.ps1',
        'tests/phase3/photos-app-v4-synthetic-cold-restart-protocol.ps1',
        'tests/phase3/v4-synthetic-phase-ab-attestation-protocol.ps1',
        'infra/scripts/dev.ps1',
        # Evidence processing itself is part of the release boundary. A
        # change to the normalizer or this verifier invalidates an earlier
        # gate rather than silently applying old evidence with new logic.
        'infra/scripts/normalize-v4-synthetic-phase-ab-evidence.ps1',
        'infra/scripts/attest-v4-synthetic-phase-ab.ps1'
    )
    $digests = [ordered]@{}
    foreach ($relative in $relativePaths) {
        if ($relative -notmatch '^[A-Za-z0-9_./-]+$') { Stop-V4SyntheticAcceptance 'source_path_contract_invalid' }
        $path = Join-Path $projectRoot $relative.Replace('/', '\')
        if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { Stop-V4SyntheticAcceptance 'acceptance_source_missing' }
        $digest = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
        if ($digest -notmatch '^[a-f0-9]{64}$') { Stop-V4SyntheticAcceptance 'acceptance_source_digest_invalid' }
        $digests[$relative] = $digest
    }
    return $digests
}

function Read-StrictUtf8Lines([string]$Path, [string]$Code) {
    try { $bytes = [IO.File]::ReadAllBytes($Path); $text = [Text.UTF8Encoding]::new($false, $true).GetString($bytes) }
    catch { Stop-V4SyntheticAcceptance $Code }
    if ($text.Contains("`0")) { Stop-V4SyntheticAcceptance $Code }
    return @($text -split "`r?`n" | ForEach-Object { $_.Trim() } | Where-Object { $_ -ne '' })
}

function Assert-EvidenceRecord([string]$EvidenceRoot, [string]$Leaf, [string]$Pattern, [string]$CompletionPattern, [string]$Code, [string[]]$AllowedPatterns) {
    if ($Leaf -notmatch '^[a-z0-9-]{3,64}\.out$') { Stop-V4SyntheticAcceptance 'evidence_name_invalid' }
    $path = Assert-PlainPrivateFile (Join-Path $EvidenceRoot $Leaf) $Code
    $lines = Read-StrictUtf8Lines $path $Code
    if ($null -eq $AllowedPatterns -or $AllowedPatterns.Count -eq 0) { Stop-V4SyntheticAcceptance 'evidence_allowlist_invalid' }
    foreach ($line in $lines) {
        # The attestation does not retain general-purpose command logs. Every
        # accepted line must be a narrow, redaction-safe protocol record; this
        # rejects a transcript accidentally containing a path, token, cookie,
        # password, screenshot name, or browser diagnostic detail.
        if (@($AllowedPatterns | Where-Object { $line -match $_ }).Count -ne 1) { Stop-V4SyntheticAcceptance $Code }
    }
    $records = @($lines | Where-Object { $_ -match $Pattern })
    if ($records.Count -ne 1) { Stop-V4SyntheticAcceptance $Code }
    $completion = @($lines | Where-Object { $_ -match $CompletionPattern })
    # The completion record is emitted only after the runner's finally block
    # has restored its synthetic fixture/baseline and removed its ephemeral
    # Chrome profile. It must be the terminal protocol line; otherwise a PASS
    # observed before cleanup can never become migration evidence.
    if ($completion.Count -ne 1 -or $lines.Count -lt 2 -or $lines[$lines.Count - 1] -cne $completion[0]) { Stop-V4SyntheticAcceptance $Code }
    # A transcript may contain safe stage records, but it can never contain a
    # failed gate alongside the claimed PASS evidence.
    if (@($lines | Where-Object { $_ -match '=FAIL\b' }).Count -ne 0) { Stop-V4SyntheticAcceptance $Code }
    $sha256 = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($sha256 -notmatch '^[a-f0-9]{64}$') { Stop-V4SyntheticAcceptance $Code }
    return @{ leaf = $Leaf; sha256 = $sha256 }
}

function Get-GatePath([string]$Name) {
    if ($Name -notmatch '^v4-synthetic-phase-ab-[0-9]{8}T[0-9]{6}Z\.json$') { Stop-V4SyntheticAcceptance 'gate_name_invalid' }
    return (Join-Path $attestationRoot $Name)
}

function Write-Attestation([string]$Path, [hashtable]$Evidence) {
    if (Test-Path -LiteralPath $Path) { Stop-V4SyntheticAcceptance 'gate_already_exists' }
    $record = [ordered]@{
        format = 1
        scope = 'V4_SYNTHETIC_PHASE_AB'
        environment = 'SYNTHETIC_8091'
        created_at = (Get-Date).ToUniversalTime().ToString('o')
        source_head = Get-CurrentHead
        source_digests = Get-SourceDigests
        browser = 'GOOGLE_CHROME_STABLE'
        gates = [ordered]@{
            synthetic_desktop_chrome = 'PASS'
            synthetic_search_overlay = 'PASS'
            synthetic_viewer = 'PASS'
            synthetic_scope_projections = 'PASS'
            synthetic_upload_era = 'PASS'
            synthetic_mediaguard = 'PASS'
            synthetic_server_restart = 'PASS'
            synthetic_baseline = 'PASS_72_72_8'
        }
        evidence = $Evidence
    }
    [IO.File]::WriteAllText($Path, (($record | ConvertTo-Json -Depth 8 -Compress) + [Environment]::NewLine), [Text.UTF8Encoding]::new($false))
    Set-ClassArchiveOwnerOnlyFileAcl -Path $Path
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $Path
    return (Get-FileHash -LiteralPath $Path -Algorithm SHA256).Hash.ToLowerInvariant()
}

function Read-Attestation([string]$Name) {
    $path = Assert-PlainPrivateFile (Get-GatePath $Name) 'gate_missing'
    Assert-ClassArchiveOwnerOnlyFileAcl -Path $path
    try { $record = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json -ErrorAction Stop }
    catch { Stop-V4SyntheticAcceptance 'gate_json_invalid' }
    if ([int](Get-Property $record 'format') -ne 1 -or [string](Get-Property $record 'scope') -ne 'V4_SYNTHETIC_PHASE_AB' -or
        [string](Get-Property $record 'environment') -ne 'SYNTHETIC_8091' -or [string](Get-Property $record 'browser') -ne 'GOOGLE_CHROME_STABLE') {
        Stop-V4SyntheticAcceptance 'gate_contract_invalid'
    }
    $head = Get-CurrentHead
    if ([string](Get-Property $record 'source_head') -ne $head) { Stop-V4SyntheticAcceptance 'gate_source_head_stale' }
    $expectedDigests = Get-SourceDigests
    $actualDigests = Get-Property $record 'source_digests'
    foreach ($entry in $expectedDigests.GetEnumerator()) {
        if ([string](Get-Property $actualDigests $entry.Key) -ne [string]$entry.Value) { Stop-V4SyntheticAcceptance 'gate_source_digest_stale' }
    }
    if (@($actualDigests.PSObject.Properties).Count -ne $expectedDigests.Count) { Stop-V4SyntheticAcceptance 'gate_source_digest_shape_invalid' }
    $gates = Get-Property $record 'gates'
    $expectedGates = [ordered]@{
        synthetic_desktop_chrome = 'PASS'; synthetic_search_overlay = 'PASS'; synthetic_viewer = 'PASS'; synthetic_scope_projections = 'PASS';
        synthetic_upload_era = 'PASS'; synthetic_mediaguard = 'PASS'; synthetic_server_restart = 'PASS'; synthetic_baseline = 'PASS_72_72_8'
    }
    foreach ($entry in $expectedGates.GetEnumerator()) {
        if ([string](Get-Property $gates $entry.Key) -ne [string]$entry.Value) { Stop-V4SyntheticAcceptance 'gate_required_evidence_missing' }
    }
    if (@($gates.PSObject.Properties).Count -ne $expectedGates.Count) { Stop-V4SyntheticAcceptance 'gate_shape_invalid' }
    $evidence = Get-Property $record 'evidence'
    $expectedEvidence = @('chrome-main','chrome-deep','scope','upload','restart')
    foreach ($name in $expectedEvidence) {
        $entry = Get-Property $evidence $name
        if ([string](Get-Property $entry 'leaf') -notmatch '^[a-z0-9-]{3,64}\.out$' -or [string](Get-Property $entry 'sha256') -notmatch '^[a-f0-9]{64}$') {
            Stop-V4SyntheticAcceptance 'gate_evidence_shape_invalid'
        }
    }
    if (@($evidence.PSObject.Properties).Count -ne $expectedEvidence.Count) { Stop-V4SyntheticAcceptance 'gate_evidence_shape_invalid' }
    $sha256 = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($sha256 -notmatch '^[a-f0-9]{64}$') { Stop-V4SyntheticAcceptance 'gate_sha256_invalid' }
    return @{ name = $Name; sha256 = $sha256; record = $record }
}

try {
    [void](Assert-IgnoredDirectory $attestationRoot)
    Assert-CleanAcceptanceCheckout
    if ($Action -eq 'Record') {
        if ([string]::IsNullOrWhiteSpace($EvidenceDirectory)) { Stop-V4SyntheticAcceptance 'evidence_directory_required' }
        $evidenceRoot = Assert-IgnoredDirectory $EvidenceDirectory
        if ([string]::IsNullOrWhiteSpace($GateName)) { $GateName = 'v4-synthetic-phase-ab-' + (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ') + '.json' }
    $gatePath = Get-GatePath $GateName
        $chromeMainPattern = '^V4_CHROME_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
        $chromeMainCompletionPattern = '^V4_CHROME_QA_COMPLETE=PASS$'
        $chromeDeepPattern = '^V4_CHROME_DEEP_QA=PASS assertions=[0-9]+ screenshots=[0-9]+ channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
        $mediaGuardPattern = '^V4_CHROME_DEEP_MEDIAGUARD=PASS source=dev\.ps1:test-phase0\+test-phase1$'
        $chromeDeepCompletionPattern = '^V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS$'
        # Phase A/B is not allowed to silently skip People.  A synthetic scope
        # run that says people_required=no is useful diagnostics, but it is not
        # sufficient evidence for the V18 private migration release gate.
        $scopePattern = '^V4_SCOPE_PROJECTION=PASS assertions=[0-9]+ screenshots=[0-9]+ chrome_version=[0-9.]+ people_required=yes$'
        $scopeCompletionPattern = '^V4_SCOPE_PROJECTION_COMPLETE=PASS$'
        $uploadPattern = '^V4_CHROME_UPLOAD_LIFECYCLE=PASS assertions=[0-9]+ uploads=5 channel=chrome chrome_product=chrome chrome_version=[0-9.]+$'
        $uploadCompletionPattern = '^V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS$'
        $restartPattern = '^V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8$'
        $restartCompletionPattern = '^V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS$'
        $evidence = [ordered]@{
            'chrome-main' = Assert-EvidenceRecord $evidenceRoot 'chrome-main.out' $chromeMainPattern $chromeMainCompletionPattern 'chrome_main_evidence_invalid' @($chromeMainPattern, $chromeMainCompletionPattern)
            'chrome-deep' = Assert-EvidenceRecord $evidenceRoot 'chrome-deep.out' $chromeDeepPattern $chromeDeepCompletionPattern 'chrome_deep_evidence_invalid' @($chromeDeepPattern, $mediaGuardPattern, $chromeDeepCompletionPattern)
            'scope' = Assert-EvidenceRecord $evidenceRoot 'scope.out' $scopePattern $scopeCompletionPattern 'scope_evidence_invalid' @($scopePattern, $scopeCompletionPattern)
            'upload' = Assert-EvidenceRecord $evidenceRoot 'upload.out' $uploadPattern $uploadCompletionPattern 'upload_evidence_invalid' @($uploadPattern, $uploadCompletionPattern)
            'restart' = Assert-EvidenceRecord $evidenceRoot 'restart.out' $restartPattern $restartCompletionPattern 'restart_evidence_invalid' @($restartPattern, $restartCompletionPattern)
        }
        $deepLines = Read-StrictUtf8Lines (Join-Path $evidenceRoot 'chrome-deep.out') 'chrome_deep_evidence_invalid'
        if (@($deepLines | Where-Object { $_ -match $mediaGuardPattern }).Count -ne 1) { Stop-V4SyntheticAcceptance 'mediaguard_evidence_invalid' }
        $sha256 = Write-Attestation $gatePath $evidence
        Write-Output ('V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Record gate=' + $GateName + ' sha256=' + $sha256 + ' scope=SYNTHETIC_8091 browser=GOOGLE_CHROME_STABLE media=MEDIAGUARD_REGRESSION')
        exit 0
    }

    if ([string]::IsNullOrWhiteSpace($GateName)) { Stop-V4SyntheticAcceptance 'gate_name_required' }
    $gate = Read-Attestation $GateName
    Write-Output ('V4_SYNTHETIC_PHASE_AB_ATTESTATION=PASS action=Verify gate=' + $gate.name + ' sha256=' + $gate.sha256 + ' scope=SYNTHETIC_8091 browser=GOOGLE_CHROME_STABLE media=MEDIAGUARD_REGRESSION')
}
catch {
    $message = [string]$_.Exception.Message
    $code = if ($message -match '^V4_SYNTHETIC_ACCEPTANCE_STOP:([a-z0-9_]{1,128})$') { $Matches[1] } else { 'v4_synthetic_acceptance_failed' }
    Write-Output "V4_SYNTHETIC_PHASE_AB_ATTESTATION=FAIL action=$Action code=$code"
    exit 2
}
