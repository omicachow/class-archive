[CmdletBinding()]
param()

# Static-only public protocol check for the dedicated deep V4 Chrome runner.
# It starts no browser/container and reads no ignored credential or runtime
# data. Runtime evidence is intentionally a separate, blocked phase.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-deep-qa.mjs'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-deep-qa.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-chrome-deep-qa.md'
$fixturePath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-viewer-fixture.php'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Source $runnerPath 'v4_chrome_deep_runner_missing'
$wrapper = Read-Source $wrapperPath 'v4_chrome_deep_wrapper_missing'
$docs = Read-Source $docsPath 'v4_chrome_deep_docs_missing'
$fixture = Read-Source $fixturePath 'v4_chrome_deep_viewer_fixture_missing'

# Chrome Stable and a newly-created persistent profile are hard requirements.
Assert-True ($runner.Contains('chromium.launchPersistentContext(profile')) 'v4_chrome_deep_persistent_context_missing'
Assert-True ($runner.Contains("channel: 'chrome'")) 'v4_chrome_deep_stable_channel_missing'
Assert-True (-not $runner.Contains('executablePath')) 'v4_chrome_deep_executable_path_forbidden'
Assert-True ($runner.Contains("check(!fs.existsSync(profile), 'profile_not_fresh')")) 'v4_chrome_deep_profile_freshness_missing'
Assert-True ($runner.Contains("serviceWorkers: 'block'") -and $runner.Contains('acceptDownloads: false')) 'v4_chrome_deep_context_hardening_missing'
Assert-True ($runner.Contains('context.newCDPSession(page)') -and $runner.Contains("session.send('Browser.getVersion')")) 'v4_chrome_deep_cdp_version_missing'
Assert-True ($runner.Contains('/^Chrome\/(\d+(?:\.\d+){1,4})$/') -and $runner.Contains('headless: false')) 'v4_chrome_deep_headed_stable_evidence_missing'

# The companion is synthetic-only: only 8090/8091 are legal document/API
# destinations, private real 8190/8191 and public bindings are forbidden.
Assert-True ($runner.Contains("['8090', '8091']") -and $runner.Contains("url.hostname === '127.0.0.1'") -and $runner.Contains('route.abort()')) 'v4_chrome_deep_loopback_allowlist_missing'
Assert-True (-not $runner.Contains('8191') -and -not $runner.Contains('8190') -and -not $runner.Contains('0.0.0.0')) 'v4_chrome_deep_private_or_public_endpoint_forbidden'
Assert-True ($runner.Contains("document?.version === 1 && document.environment === 'synthetic'") -and $runner.Contains("'anonymous,classmate,family,teacher'") -and $runner.Contains('familyDeniedPhotoId')) 'v4_chrome_deep_credential_scope_missing'
Assert-True ($runner.Contains('CLASS_ARCHIVE_V4_DEEP_VIEWER_FIXTURE_FILE') -and $runner.Contains('readViewerFixture') -and $runner.Contains("'commentIds,environment,photoIds,run,version'")) 'v4_chrome_deep_fixture_contract_missing'

# Viewer coverage must retain the MediaGuard BFF preview path, filmstrip and
# adjacent preload while Family's read-only UI is verified server-side too.
foreach ($token in @('viewer_mediaguard_preview', 'viewer_direct_media_forbidden', 'viewer_filmstrip', 'viewer_adjacent_preload', 'viewer_keyboard_next', 'viewer_keyboard_previous', 'viewer_escape_close', 'viewer_mobile_comment_sheet', 'viewer_zoom', 'family_comment_readonly_visible', 'family_comment_server_denied', 'anonymous_comment_fixture_api_available', 'anonymous_comment_context_pseudonym_distinct', 'anonymous_comment_api_identity_redacted', 'anonymous_comment_html_identity_redacted')) {
    Assert-True ($runner.Contains($token)) ('v4_chrome_deep_viewer_or_comment_gate_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains("/api/class-archive/comments/create") -and $runner.Contains('result.status === 403')) 'v4_chrome_deep_family_comment_server_gate_missing'
Assert-True ($runner.Contains('classmateid') -and $runner.Contains('underlyinguserid')) 'v4_chrome_deep_anonymous_identifier_scan_missing'
foreach ($token in @('assertFamilyKnownLivingDenied', 'family_known_living_timeline_denied', 'family_known_living_mediaguard_denied', 'family_known_living_viewer_denied', '`/api/assets/${id}/thumbnail?size=thumbnail`', '`/api/assets/${id}/original`', "{ Range: 'bytes=0-1' }")) {
    Assert-True ($runner.Contains($token)) ('v4_chrome_deep_family_known_living_gate_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# Era UI has two explicit choices for direct members. Restricted principals
# must not receive that UI and must receive 403 before direct publication.
foreach ($token in @('era_upload_two_choices', 'era_upload_client_required', 'era_upload_heritage_album_choices', 'era_upload_living_album_choices', 'member_upload_missing_era_denied', 'member_upload_missing_era_no_mutation', 'direct_member_upload_denied', 'family_pending_upload_entry', 'family_living_upload_copy_hidden', 'anonymous_upload_entry_hidden')) {
    Assert-True ($runner.Contains($token)) ('v4_chrome_deep_era_gate_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains("body.set('era', 'LIVING')") -and $runner.Contains('result.status === 403')) 'v4_chrome_deep_living_tamper_denial_missing'
Assert-True ($runner.Contains('non-publishing malformed') -and $runner.Contains('result.status === 400')) 'v4_chrome_deep_missing_era_server_denial_missing'
Assert-True (-not $runner.Contains('.setInputFiles(') -and -not $runner.Contains("body.set('member_photo', file)")) 'v4_chrome_deep_successful_upload_forbidden'

# Wrapper roots are ignored, random, ACL-checked and clean up only their own
# non-reparse persistent profile. Its only compose use is a bounded `exec`
# against an already-running synthetic Piwigo container; no lifecycle command
# may start/stop/restart Docker.
Assert-True ($wrapper.Contains("'.codex-work\browser\photos-app-v4-chrome-deep'") -and $wrapper.Contains("'.codex-work\screenshots\photos-app-v4-chrome-deep'")) 'v4_chrome_deep_ignored_output_roots_missing'
Assert-True ($wrapper.Contains('[Security.Cryptography.RandomNumberGenerator]::Create()')) 'v4_chrome_deep_random_run_id_missing'
Assert-True ($wrapper.Contains('Assert-ClassArchiveOwnerOnlyFileAcl') -and $wrapper.Contains('check-ignore --quiet --no-index')) 'v4_chrome_deep_private_boundary_missing'
Assert-True ($wrapper.Contains("CLASS_ARCHIVE_V4_DEEP_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'") -and $wrapper.Contains("CLASS_ARCHIVE_V4_DEEP_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'")) 'v4_chrome_deep_synthetic_origin_missing'
Assert-True ($wrapper.Contains('Remove-Item -LiteralPath $userDataRoot -Recurse -Force') -and $wrapper.Contains('ReparsePoint')) 'v4_chrome_deep_profile_cleanup_missing'
Assert-True ($wrapper.Contains('v4_chrome_deep_profile_cleanup_boundary') -and $wrapper.Contains('v4_chrome_deep_profile_cleanup_failed') -and $wrapper.Contains('-ErrorAction Stop')) 'v4_chrome_deep_profile_cleanup_must_fail_closed'
Assert-True ($wrapper.Contains('test-phase0') -and $wrapper.Contains('test-phase1') -and $wrapper.Contains('CLASS_IDENTITY_HTTP=PASS')) 'v4_chrome_deep_mediaguard_and_freeze_regressions_missing'
Assert-True ($wrapper.Contains('Assert-V4DeepSyntheticComposeEnvironment') -and $wrapper.Contains("COMPOSE_PROJECT_NAME = 'class_archive_piwigo'") -and $wrapper.Contains("CLASS_ARCHIVE_HTTP_PORT = '8090'")) 'v4_chrome_deep_synthetic_compose_identity_missing'
Assert-True ($wrapper.Contains('Invoke-V4DeepViewerFixture') -and $wrapper.Contains("'docker', 'compose'") -and $wrapper.Contains("'exec', '-T', '--user', 'nginx'") -and $wrapper.Contains('CLASS_ARCHIVE_V4_VIEWER_FIXTURE=1')) 'v4_chrome_deep_fixture_exec_boundary_missing'
Assert-True ($wrapper.Contains('CLASS_ARCHIVE_V4_DEEP_VIEWER_FIXTURE_FILE') -and $wrapper.Contains('viewer-fixture.json') -and $wrapper.Contains('V4_VIEWER_FIXTURE=READY') -and $wrapper.Contains('V4_VIEWER_FIXTURE=CLEANUP')) 'v4_chrome_deep_fixture_handoff_missing'
Assert-True ($wrapper.Contains('$fixtureAttempted') -and $wrapper.Contains('$fixtureCleanupFailure') -and $wrapper.Contains('finally')) 'v4_chrome_deep_fixture_finally_cleanup_missing'
$privateDriveRoot = 'M' + [char]58 + [char]92
foreach ($forbidden in @('dev.ps1 up', 'dev.ps1 stop', 'dev.ps1 down', 'docker compose up', 'docker compose down', 'docker compose stop', 'docker compose restart', '8191', '8190', $privateDriveRoot)) {
    Assert-True (-not $wrapper.Contains($forbidden)) ('v4_chrome_deep_forbidden_wrapper_token_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The narrowly scoped PHP helper can only run inside the existing unprivileged
# synthetic container. It needs an already-attested V18 schema, creates only
# two exact marker comments through the existing domain service, and removes
# those exact comment/audit pairs in a transaction.
foreach ($token in @("getenv('CLASS_ARCHIVE_V4_VIEWER_FIXTURE') !== '1'", 'posix_geteuid() === 0', "const CAVF_PIWIGO_ROOT = '/var/www/html/piwigo'", "'/workspace/tests/phase3/photos-app-v4-viewer-fixture.php'", 'Schema::CURRENT_VERSION !== 18', 'Schema::fromPiwigo((string) CLASS_IDENTITY_VERSION)->verifyCurrent()', 'PhotoCommentService::fromPiwigo()', "'fixture-anonymous'", "'v4-viewer-fixture-'", 'PHOTO_COMMENT_CREATE', 'FOR UPDATE', '$repository->transaction')) {
    Assert-True ($fixture.Contains($token)) ('v4_chrome_deep_fixture_safety_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($forbidden in @('unlink(', 'delete_elements(', '8191', '8190', '0.0.0.0', $privateDriveRoot)) {
    Assert-True (-not $fixture.Contains($forbidden)) ('v4_chrome_deep_fixture_forbidden_token_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The optional media suite combines the existing synthetic Phase 0 media
# matrix with Phase 1's freeze/revoke session proof. It is HTTP evidence, not
# a replacement for the Chrome result, and must not run without opt-in.
Assert-True ($wrapper.Contains('[switch]$RunMediaGuardRegression') -and $wrapper.Contains("'V4_CHROME_DEEP_MEDIAGUARD=PASS source=dev.ps1:test-phase0+test-phase1'")) 'v4_chrome_deep_mediaguard_opt_in_missing'
Assert-True ($wrapper.Contains('infra\scripts\dev.ps1') -and $wrapper.Contains('test-phase0') -and $wrapper.Contains('MEDIA_GUARD_HTTP=PASS') -and $wrapper.Contains('test-phase1') -and $wrapper.Contains('CLASS_IDENTITY_HTTP=PASS')) 'v4_chrome_deep_mediaguard_and_freeze_boundary_missing'
Assert-True ($wrapper.Contains('V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS') -and $wrapper.Contains('V4_CHROME_DEEP_COMPLETE=PASS mediaguard=SKIPPED')) 'v4_chrome_deep_post_finally_completion_missing'
$deepFinally = $wrapper.LastIndexOf('finally {', [StringComparison]::Ordinal)
$deepCompletion = $wrapper.IndexOf("Write-Output 'V4_CHROME_DEEP_COMPLETE=PASS mediaguard=PASS'", [StringComparison]::Ordinal)
Assert-True ($deepFinally -ge 0 -and $deepCompletion -gt $deepFinally) 'v4_chrome_deep_completion_must_follow_finally'

foreach ($boundary in @('does not upload a file', 'does not create a comment itself', 'does not start or stop Docker', 'Family', 'Known LIVING', 'GET', 'HEAD', 'Range', 'context pseudonym', 'test-phase0', 'test-phase1', 'not final V4 acceptance evidence')) {
    Assert-True ($docs.Contains($boundary)) ('v4_chrome_deep_docs_boundary_missing_' + ($boundary -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_CHROME_DEEP_QA_PROTOCOL=PASS assertions=$assertions"
