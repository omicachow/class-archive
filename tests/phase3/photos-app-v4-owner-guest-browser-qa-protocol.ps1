[CmdletBinding()]
param()

# Static-only contract for the Owner-private Guest Chrome runner.  It never
# starts Chrome, Docker, an HTTP service, or a private fixture; it only proves
# that the future runtime harness remains read-only and cannot embed private
# media identifiers in the repository.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-guest-browser-qa.mjs'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-guest-browser-qa.ps1'
$guardPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-localhost-guard.mjs'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True ($Text.Contains($Needle)) $Code
}

function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True (-not $Text.Contains($Needle)) $Code
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'guest_runner_missing'
Assert-True (Test-Path -LiteralPath $wrapperPath -PathType Leaf) 'guest_wrapper_missing'
Assert-True (Test-Path -LiteralPath $guardPath -PathType Leaf) 'guest_localhost_guard_missing'

$node = (Get-Command node -ErrorAction SilentlyContinue).Source
Assert-True (-not [string]::IsNullOrWhiteSpace($node)) 'guest_protocol_node_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'guest_runner_parse_invalid'

$tokens = $null
$errors = $null
[System.Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$errors) | Out-Null
Assert-True (@($errors).Count -eq 0) 'guest_wrapper_parse_invalid'

$runner = [IO.File]::ReadAllText($runnerPath, [Text.UTF8Encoding]::new($false, $true))
$wrapper = [IO.File]::ReadAllText($wrapperPath, [Text.UTF8Encoding]::new($false, $true))

# Chrome Stable must be headed, independently scoped and constrained before
# navigation.  The shared process-start guard blocks Chrome startup traffic;
# the route guard then denies any non-local or unsafe browser request.
Assert-Contains $runner "from './photos-app-v4-chrome-localhost-guard.mjs'" 'guest_localhost_guard_import_missing'
Assert-Contains $runner "channel: 'chrome'" 'guest_chrome_channel_missing'
Assert-Contains $runner 'headless: false' 'guest_headed_chrome_missing'
Assert-Contains $runner 'chromium.launchPersistentContext' 'guest_persistent_profile_missing'
Assert-Contains $runner "serviceWorkers: 'block'" 'guest_service_worker_block_missing'
Assert-Contains $runner 'acceptDownloads: false' 'guest_download_block_missing'
Assert-Contains $runner 'Browser.getVersion' 'guest_chrome_version_evidence_missing'
Assert-Contains $runner '/^Chrome\/' 'guest_chrome_stable_product_missing'
Assert-Contains $runner 'context.route' 'guest_context_route_guard_missing'
Assert-Contains $runner 'SAFE_HTTP_METHODS' 'guest_safe_http_methods_missing'
Assert-Contains $runner "new Set(['GET', 'HEAD', 'OPTIONS'])" 'guest_safe_http_method_set_invalid'
Assert-Contains $runner 'unexpectedNetwork = true' 'guest_nonlocal_request_fail_closed_missing'
Assert-Contains $runner '...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS' 'guest_process_network_guard_missing'
Assert-Contains $runner "[configuration.coreOrigin.port, configuration.photoOrigin.port]" 'guest_exact_loopback_port_allowlist_missing'
Assert-Contains $runner "localOrigin('CLASS_ARCHIVE_V4_OWNER_GUEST_CORE_ORIGIN', 8190)" 'guest_core_loopback_origin_missing'
Assert-Contains $runner "localOrigin('CLASS_ARCHIVE_V4_OWNER_GUEST_PHOTO_ORIGIN', 8191)" 'guest_photo_loopback_origin_missing'
Assert-Contains $runner "context.cookies()).length === 0" 'guest_fresh_no_cookie_proof_missing'

# The only direct media inputs are two opaque URLs from an ignored caller
# document.  The repository carries neither UUIDs nor media paths, and all
# direct probes remain GET/HEAD/Range reads through the BFF/MediaGuard path.
Assert-Contains $runner 'GUEST_MEDIA_DOCUMENT_SCOPE' 'guest_opaque_document_scope_missing'
Assert-Contains $runner "'probes,scope,version'" 'guest_opaque_document_shape_missing'
Assert-Contains $runner "'DERIVATIVE'" 'guest_derivative_probe_missing'
Assert-Contains $runner "'ORIGINAL'" 'guest_original_probe_missing'
Assert-Contains $runner 'RAW_MEDIA_URL' 'guest_opaque_media_url_validation_missing'
Assert-Contains $runner 'api/assets' 'guest_media_bff_path_missing'
Assert-Contains $runner "Range: 'bytes=0-31'" 'guest_media_range_probe_missing'
Assert-Contains $runner "'HEAD'" 'guest_media_head_probe_missing'
Assert-Contains $runner 'x-accel-redirect' 'guest_media_accel_disclosure_guard_missing'
Assert-Contains $runner 'maxRedirects: 0' 'guest_direct_probe_redirect_guard_missing'
Assert-Contains $runner 'status === 401 || status === 403' 'guest_direct_probe_denial_missing'
Assert-Contains $runner 'cache-control' 'guest_direct_probe_no_store_guard_missing'
Assert-Contains $runner 'CLASS_ARCHIVE_V4_OWNER_GUEST_MEDIA_PROBE_DOCUMENT' 'guest_opaque_document_environment_missing'
Assert-Contains $wrapper 'opaque_media_probe_document_required' 'guest_opaque_document_required_missing'
Assert-Contains $wrapper 'Assert-ClassArchiveOwnerOnlyFileAcl -Path $item.FullName' 'guest_opaque_document_acl_missing'
Assert-Contains $wrapper '$probeRoot' 'guest_opaque_document_private_root_missing'
Assert-Contains $wrapper 'git -C $projectRoot check-ignore --quiet --no-index' 'guest_opaque_document_ignore_guard_missing'
Assert-Contains $wrapper 'git -C $projectRoot ls-files' 'guest_opaque_document_tracked_guard_missing'
Assert-Contains $wrapper 'Assert-V4OwnerGuestNoReparseAncestor' 'guest_reparse_guard_missing'

# App, API and administration requests are all verified unauthenticated.  A
# normal product route must land on the Core sign-in page, not return an SPA
# shell; a Back navigation returns only to the non-sensitive legal notice.
foreach ($token in @(
    "'/api/me'", "'/api/class-archive/product-state'", "'/api/class-archive/home'",
    "'/api/class-archive/timeline?limit=1'", "'/api/albums'", "'/api/people'",
    "'/api/class-archive/manage/people'", "'/api/class-archive/manage/options'",
    "'/admin.php?page=plugin-ClassIdentity'", "'/home'", "'/people/manage'", "'/class-archive-core/admin'",
    'form[name="login_form"]', '[data-photo-app="true"]', 'page.goBack', 'pageshow', 'bfcacheObserved'
)) {
    Assert-Contains $runner $token ('guest_denial_or_history_coverage_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-Contains $runner 'assertGuestApiDenial' 'guest_api_matrix_missing'
Assert-Contains $runner 'assertGuestCoreAdminDenial' 'guest_core_admin_matrix_missing'
Assert-Contains $runner 'assertGuestMediaDenial' 'guest_media_matrix_missing'
Assert-Contains $runner 'assertGuestDocumentDenial' 'guest_document_matrix_missing'

# The wrapper has no Docker/WSL/credential broker path.  It invokes only the
# bounded Node runner, creates a one-use ignored profile, then removes it
# after Chrome closes.  Its public boundary exposes a compact PASS/FAIL line.
Assert-Contains $wrapper 'ConfirmGuestReadOnlyAcceptance' 'guest_explicit_confirmation_missing'
Assert-Contains $wrapper 'explicit_guest_read_only_confirmation_required' 'guest_confirmation_fail_closed_missing'
Assert-Contains $wrapper 'Invoke-ClassArchiveBoundedNative' 'guest_bounded_node_runner_missing'
Assert-Contains $wrapper '$browserTimeoutSeconds = 180' 'guest_browser_timeout_missing'
Assert-Contains $wrapper 'New-V4OwnerGuestRunId' 'guest_fresh_execution_id_missing'
Assert-Contains $wrapper 'New-V4OwnerGuestPrivateDirectory' 'guest_private_profile_creation_missing'
Assert-Contains $wrapper 'Remove-V4OwnerGuestPrivateDirectory' 'guest_private_profile_cleanup_missing'
Assert-Contains $wrapper "'CLASS_ARCHIVE_V4_OWNER_GUEST_PROFILE_ROOT'" 'guest_profile_environment_missing'
Assert-Contains $wrapper "'CLASS_ARCHIVE_V4_OWNER_GUEST_MEDIA_PROBE_DOCUMENT'" 'guest_probe_environment_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8190/'" 'guest_wrapper_core_origin_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8191/'" 'guest_wrapper_photo_origin_missing'
Assert-Contains $wrapper "'V4_OWNER_GUEST_CHROME_QA=PASS'" 'guest_sanitized_pass_output_missing'
Assert-Contains $wrapper "'V4_OWNER_GUEST_CHROME_QA=FAIL code='" 'guest_sanitized_failure_output_missing'
Assert-Contains $wrapper "'^V4_OWNER_GUEST_CHROME_QA=(?:PASS assertions=[1-9][0-9]*|FAIL code=[a-z0-9_]{1,100})$'" 'guest_node_output_filter_missing'

$privateDrivePrefix = ([char]77).ToString() + ':' + '\\'
foreach ($forbidden in @(
    'docker', 'wsl.exe', 'compose', 'setInputFiles', 'addCookies',
    '/member-upload', 'classmate', 'teacher', 'family', 'anonymous',
    'MediaProbeDocument)', '0.0.0.0', 'https://', $privateDrivePrefix, 'source_root',
    'relative_source_path', 'original_filename', 'writeFileSync', '.screenshot('
)) {
    Assert-NotContains $runner $forbidden ('guest_runner_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($forbidden in @(
    'docker', 'wsl.exe', 'compose', 'Start-Process', 'Set-Content', 'Out-File',
    '0.0.0.0', 'https://', $privateDrivePrefix, 'source_root', 'relative_source_path', 'original_filename'
)) {
    Assert-NotContains $wrapper $forbidden ('guest_wrapper_forbidden_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "V4_OWNER_GUEST_CHROME_QA_PROTOCOL=PASS assertions=$assertions"
