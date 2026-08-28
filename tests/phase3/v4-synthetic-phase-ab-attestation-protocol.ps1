[CmdletBinding()]
param()

# Static-only contract for the ignored Phase A/B Synthetic 8091 V4 acceptance
# attestation. It does not run Chrome, Docker, HTTP probes, or any private
# Owner stack. Its job is to keep the later private migration gate honest.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$attesterPath = Join-Path $projectRoot 'infra\scripts\attest-v4-synthetic-phase-ab.ps1'
$normalizerPath = Join-Path $projectRoot 'infra\scripts\normalize-v4-synthetic-phase-ab-evidence.ps1'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Index-OfOrFail([string]$Text, [string]$Needle, [string]$Code, [int]$Start = 0) {
    $index = $Text.IndexOf($Needle, $Start, [StringComparison]::Ordinal)
    Assert-True ($index -ge 0) $Code
    return $index
}

Assert-True (Test-Path -LiteralPath $attesterPath -PathType Leaf) 'v4_phase_ab_attester_missing'
Assert-True (Test-Path -LiteralPath $normalizerPath -PathType Leaf) 'v4_phase_ab_evidence_normalizer_missing'
$attester = [IO.File]::ReadAllText($attesterPath)
$normalizer = [IO.File]::ReadAllText($normalizerPath)

Assert-True ($attester -match "(?s)\[ValidateSet\('Record', 'Verify'\)\]") 'v4_phase_ab_action_surface_invalid'
Assert-True ($attester.Contains(".codex-work\v4-synthetic-acceptance")) 'v4_phase_ab_ignored_root_missing'
Assert-True ($attester.Contains('function Assert-IgnoredDirectory') -and $attester.Contains('check-ignore --quiet --no-index') -and $attester.Contains('ls-files')) 'v4_phase_ab_git_ignore_boundary_missing'
Assert-True ($attester.Contains('Set-ClassArchiveOwnerOnlyFileAcl') -and $attester.Contains('Assert-ClassArchiveOwnerOnlyFileAcl')) 'v4_phase_ab_owner_acl_boundary_missing'
Assert-True ($attester.Contains('gate_name_invalid') -and $attester.Contains('gate_already_exists') -and $attester.Contains('gate_sha256_invalid')) 'v4_phase_ab_immutable_gate_boundary_missing'
Assert-True ($attester.Contains('source_head') -and $attester.Contains('source_digests') -and $attester.Contains('Get-CurrentHead') -and $attester.Contains('gate_source_head_stale') -and $attester.Contains('gate_source_digest_stale')) 'v4_phase_ab_source_drift_detection_missing'
Assert-True ($attester.Contains('function Assert-CleanAcceptanceCheckout') -and $attester.Contains('status --porcelain=v1 --untracked-files=all') -and $attester.Contains('acceptance_checkout_not_clean')) 'v4_phase_ab_clean_checkout_requirement_missing'
Assert-True ([regex]::Matches($attester, '(?m)^\s{4}Assert-CleanAcceptanceCheckout\s*$').Count -eq 1) 'v4_phase_ab_clean_checkout_invocation_missing'
Assert-True ($attester.Contains("environment = 'SYNTHETIC_8091'") -and $attester.Contains("browser = 'GOOGLE_CHROME_STABLE'")) 'v4_phase_ab_environment_or_browser_contract_missing'
Assert-True ($attester.Contains('function Get-TrackedPhpSourcePaths') -and $attester.Contains("Get-TrackedPhpSourcePaths 'plugins/ClassIdentity'") -and $attester.Contains("Get-TrackedPhpSourcePaths 'plugins/ClassArchivePolicy'")) 'v4_phase_ab_complete_policy_source_tree_binding_missing'
Assert-True ($attester.Contains('source_tree_contract_invalid') -and $attester.Contains('source_tree_path_invalid') -and $attester.Contains('git -C $projectRoot ls-files')) 'v4_phase_ab_policy_source_tree_validation_missing'

foreach ($sourcePath in @(
    'infra/scripts/secret-file-acl.ps1',
    'infra/scripts/configure-piwigo-baseline.php',
    'infra/scripts/rebuild-photo-read-projection.php',
    'infra/docker-compose.yml',
    'infra/immich-spike/docker-compose.yml',
    'tests/support/system-admin-session.ps1',
    'plugins/ClassIdentity/src/Schema.php',
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
    'tests/phase3/photos-app-v4-chrome-qa-protocol.ps1',
    'tests/phase3/photos-app-v4-chrome-deep-qa-protocol.ps1',
    'tests/phase3/photos-app-v4-chrome-scope-projection-protocol.ps1',
    'tests/phase3/photos-app-v4-chrome-upload-lifecycle-protocol.ps1',
    'tests/phase3/photos-app-v4-chrome-localhost-guard-protocol.ps1',
    'tests/phase3/member-era-upload-contract.mjs',
    'tests/phase3/photos-app-v4-contract.mjs',
    'tests/phase3/photos-app-v4-ui-contract.mjs',
    'tests/phase3/search-context-contract.mjs',
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
    'infra/scripts/v4-synthetic-phase-a-lease.ps1',
    'tests/phase3/v4-synthetic-phase-a-lease-protocol.ps1',
    'docs/photos-app-v4-scope-people-lifecycle.md',
    'tests/phase3/photos-app-v4-scope-people-fixture.php',
    'tests/phase3/photos-app-v4-scope-people-lifecycle.ps1',
    'tests/phase3/photos-app-v4-scope-people-lifecycle-protocol.ps1',
    'tests/phase3/read-projection-runtime.ps1',
    'tests/phase3/read-projection-runtime-snapshot.php',
    'tests/phase3/read-projection-runtime-fixture.php',
    'tests/phase3/photos-app-v4-synthetic-cold-restart-snapshot.php',
    'tests/phase3/photos-app-v4-synthetic-cold-restart.ps1',
    'tests/phase3/photos-app-v4-synthetic-cold-restart-protocol.ps1',
    'tests/phase3/v4-synthetic-phase-ab-attestation-protocol.ps1',
    'infra/scripts/dev.ps1',
    'infra/scripts/normalize-v4-synthetic-phase-ab-evidence.ps1',
    'infra/scripts/attest-v4-synthetic-phase-ab.ps1'
)) {
    Assert-True ($attester.Contains("'$sourcePath'")) ('v4_phase_ab_source_digest_path_missing_' + ($sourcePath -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

foreach ($gate in @('synthetic_desktop_chrome','synthetic_search_overlay','synthetic_viewer','synthetic_scope_projections','synthetic_upload_era','synthetic_mediaguard','synthetic_server_restart','synthetic_baseline')) {
    Assert-True ($attester.Contains($gate)) ('v4_phase_ab_required_gate_missing_' + $gate)
}

foreach ($transcript in @('chrome-main.out','chrome-deep.out','scope.out','upload.out','restart.out')) {
    Assert-True ($attester.Contains("'$transcript'")) ('v4_phase_ab_transcript_missing_' + ($transcript -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-True ($attester.Contains('V4_CHROME_QA=PASS') -and $attester.Contains('V4_CHROME_DEEP_QA=PASS') -and $attester.Contains('V4_SCOPE_PROJECTION=PASS') -and $attester.Contains('V4_CHROME_UPLOAD_LIFECYCLE=PASS')) 'v4_phase_ab_chrome_evidence_contract_missing'
Assert-True ($attester.Contains('V4_CHROME_DEEP_MEDIAGUARD=PASS') -and $attester.Contains('V4_SYNTHETIC_COLD_RESTART=PASS') -and $attester.Contains('baseline=72_72_8')) 'v4_phase_ab_mediaguard_or_restart_evidence_missing'
foreach ($completion in @('V4_CHROME_QA_COMPLETE=PASS','V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS','V4_SCOPE_PROJECTION_COMPLETE=PASS','V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS','V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS')) {
    Assert-True ($attester.Contains($completion)) ('v4_phase_ab_terminal_completion_missing_' + ($completion -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($attester.Contains('$CompletionPattern') -and $attester.Contains('$lines[$lines.Count - 1] -cne $completion[0]')) 'v4_phase_ab_terminal_completion_attester_missing'
Assert-True ($attester.Contains('people_required=yes') -and -not $attester.Contains('people_required=(yes|no)')) 'v4_phase_ab_people_scope_required'
Assert-True ($attester.Contains("Where-Object { `$_ -match '=FAIL\b' }") -and $attester.Contains('gate_required_evidence_missing')) 'v4_phase_ab_failure_record_rejection_missing'
Assert-True ($attester.Contains('evidence_allowlist_invalid') -and $attester.Contains('$AllowedPatterns') -and $attester.Contains('accepted line must be a narrow, redaction-safe protocol record')) 'v4_phase_ab_transcript_redaction_allowlist_missing'
Assert-True ($attester.Contains('$requestedGateName = $Name') -and $attester.Contains('foreach ($evidenceName in $expectedEvidence)') -and $attester.Contains('name = $requestedGateName')) 'v4_phase_ab_verify_gate_name_shadowing_prevented'

# Recording an attestation must validate every transcript before writing the
# gate; verifying one must bind the report back to the checked-out source.
$recordFlow = $attester.Substring((Index-OfOrFail $attester "if (`$Action -eq 'Record')" 'v4_phase_ab_record_flow_missing'))
$recordEvidence = Index-OfOrFail $recordFlow 'Assert-EvidenceRecord' 'v4_phase_ab_record_evidence_validation_missing'
$recordWrite = Index-OfOrFail $recordFlow 'Write-Attestation' 'v4_phase_ab_record_write_missing'
Assert-True ($recordEvidence -lt $recordWrite) 'v4_phase_ab_record_must_validate_before_write'
$verifyFlow = $attester.Substring((Index-OfOrFail $attester 'Read-Attestation $GateName' 'v4_phase_ab_verify_flow_missing'))
Assert-True ($verifyFlow.Contains('source=SYNTHETIC_8091') -or $attester.Contains('scope=SYNTHETIC_8091')) 'v4_phase_ab_verify_scope_missing'

# The attester is evidence processing only. Runtime behavior belongs in the
# fixed Chrome and synthetic test runners; this helper may never claim to run
# them itself or reach an Owner/private endpoint.
foreach ($forbidden in @('docker compose', 'Start-Process', 'chromium.launch', 'playwright', '127.0.0.1:8191', 'runtime-owner', 'private-real')) {
    Assert-True (-not $attester.Contains($forbidden)) ('v4_phase_ab_runtime_or_private_reference_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Raw Chrome wrapper output may include ignored screenshot paths.  The
# normalizer must accept such a local input only under .codex-work, reject a
# failed runner, require a terminal post-finally completion record, and emit
# just the eleven exact, redaction-safe protocol records
# that the attester consumes.  It is deliberately evidence processing, not a
# replacement for a real Chrome/HTTP/cold-restart run.
Assert-True ($normalizer.Contains('.codex-work') -and $normalizer.Contains('check-ignore --quiet --no-index') -and $normalizer.Contains('ls-files')) 'v4_phase_ab_normalizer_ignored_boundary_missing'
Assert-True ($normalizer.Contains('Set-ClassArchiveOwnerOnlyFileAcl') -and $normalizer.Contains('Assert-ClassArchiveOwnerOnlyFileAcl')) 'v4_phase_ab_normalizer_owner_acl_missing'
Assert-True ($normalizer.Contains('V4_SYNTHETIC_PHASE_AB_EVIDENCE=PASS') -and $normalizer.Contains('records=11') -and $normalizer.Contains('SAFE_PROTOCOL_LINES_ONLY')) 'v4_phase_ab_normalizer_safe_output_missing'
Assert-True ($normalizer.Contains("Where-Object { `$_ -match '=FAIL\b' }") -and $normalizer.Contains('Select-ExactlyOneSafeLine')) 'v4_phase_ab_normalizer_failure_or_cardinality_missing'
Assert-True ($normalizer.Contains('Select-TerminalCompletionLine') -and $normalizer.Contains('$Lines[$Lines.Count - 1] -cne $completion')) 'v4_phase_ab_normalizer_terminal_completion_missing'
foreach ($completion in @('V4_CHROME_QA_COMPLETE=PASS','V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS','V4_SCOPE_PROJECTION_COMPLETE=PASS','V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS','V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS')) {
    Assert-True ($normalizer.Contains($completion)) ('v4_phase_ab_normalizer_completion_missing_' + ($completion -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($normalizer.Contains('people_required=yes') -and $normalizer.Contains('chrome-main.out') -and $normalizer.Contains('chrome-deep.out') -and $normalizer.Contains('scope.out') -and $normalizer.Contains('upload.out') -and $normalizer.Contains('restart.out')) 'v4_phase_ab_normalizer_fixed_safe_leaves_missing'
foreach ($forbidden in @('docker compose', 'Start-Process', 'chromium.launch', 'playwright', '127.0.0.1:8191', 'runtime-owner', 'private-real')) {
    Assert-True (-not $normalizer.Contains($forbidden)) ('v4_phase_ab_normalizer_runtime_or_private_reference_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "V4_SYNTHETIC_PHASE_AB_ATTESTATION_PROTOCOL=PASS assertions=$assertions"
