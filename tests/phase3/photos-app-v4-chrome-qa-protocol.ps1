[CmdletBinding()]
param()

# Static-only contract for the dedicated V4 Chrome Stable acceptance runner.
# It starts no browser or container, reads no ignored credential file, and
# cannot access either synthetic or private runtime data.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-qa.mjs'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-qa.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-chrome-qa.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Source $runnerPath 'v4_chrome_runner_missing'
$wrapper = Read-Source $wrapperPath 'v4_chrome_wrapper_missing'
$docs = Read-Source $docsPath 'v4_chrome_docs_missing'

# Chrome Stable must be selected by Playwright's branded channel and must use
# a fresh persistent data directory. A host executable path or the default
# Chromium launcher would not meet the acceptance-browser requirement.
Assert-True ($runner.Contains('chromium.launchPersistentContext(profile')) 'v4_chrome_persistent_context_missing'
Assert-True ($runner.Contains("channel: 'chrome'")) 'v4_chrome_stable_channel_missing'
Assert-True (-not $runner.Contains('executablePath')) 'v4_chrome_executable_path_forbidden'
Assert-True ($runner.Contains("check(!fs.existsSync(profile), 'profile_not_fresh')")) 'v4_chrome_profile_freshness_gate_missing'
Assert-True ($runner.Contains("serviceWorkers: 'block'") -and $runner.Contains('acceptDownloads: false')) 'v4_chrome_ephemeral_context_hardening_missing'
foreach ($networkFlag in @('--disable-background-networking', '--disable-component-update', '--disable-sync', '--no-pings')) {
    Assert-True ($runner.Contains($networkFlag)) ('v4_chrome_network_flag_missing_' + ($networkFlag -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# BrowserContext.browser() is nullable for persistent contexts. Version proof
# therefore has to come from the browser process, not from Playwright's
# bundled revision, the host exe metadata, or an optional Browser object.
Assert-True ($runner.Contains('context.newCDPSession(page)') -and $runner.Contains("session.send('Browser.getVersion')")) 'v4_chrome_cdp_version_evidence_missing'
Assert-True ($runner.Contains('/^Chrome\/(\d+(?:\.\d+){1,4})$/') -and $runner.Contains('headless: false')) 'v4_chrome_headed_stable_evidence_missing'
Assert-True (-not $runner.Contains('context.browser()?.version()')) 'v4_chrome_nullable_browser_version_forbidden'
Assert-True ($runner.Contains('chrome_product=${chromeProduct}') -and $runner.Contains('chrome_version=${chromeVersion}')) 'v4_chrome_bounded_version_output_missing'

# The runner may only load the two synthetic loopback services and locally
# generated browser data. It must never silently target private 8191 or a
# non-loopback host.
Assert-True ($runner.Contains("['8090', '8091']") -and $runner.Contains("url.hostname === '127.0.0.1'") -and $runner.Contains('route.abort()')) 'v4_chrome_loopback_request_allowlist_missing'
Assert-True (-not $runner.Contains('8191') -and -not $runner.Contains('8190') -and -not $runner.Contains('0.0.0.0')) 'v4_chrome_private_or_public_endpoint_forbidden'
Assert-True ($runner.Contains("doc?.version === 1 && doc.environment === 'synthetic'") -and $runner.Contains("'anonymous,classmate,family,teacher'")) 'v4_chrome_synthetic_credential_scope_missing'

# The focus-trap test must exercise the same broad, visible focusable surface
# as the dialog itself, not just the first few button/input elements.
foreach ($focusSelector in @('a[href]:not([aria-disabled="true"]):visible', 'button:not([disabled]):visible', 'input:not([disabled]):visible', 'select:not([disabled]):visible', 'textarea:not([disabled]):visible', '[tabindex]:not([tabindex="-1"]):visible')) {
    Assert-True ($runner.Contains($focusSelector)) ('v4_chrome_focus_selector_missing_' + ($focusSelector -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains("'search_combobox_semantics'") -and $runner.Contains("'search_listbox_semantics'") -and $runner.Contains("'search_combobox_arrow'") -and $runner.Contains("'search_combobox_enter'")) 'v4_chrome_search_keyboard_semantics_missing'

# The runtime runner must exercise the actual browser-visible overlay state,
# not infer these interaction paths from static app source.  Each bounded
# failure code below is intentionally distinct so an unsupported UI behavior
# cannot be reported as a broad Chrome PASS.
foreach ($gate in @(
    'search_trigger_semantics',
    'search_dialog_semantics',
    'search_background_interaction_blocked',
    'search_focus_trap_forward',
    'search_focus_trap_reverse',
    'search_focus_restore',
    'search_ctrl_k',
    'search_slash',
    'search_back_closes_overlay',
    'search_legacy_route_open',
    'search_legacy_route_cleanup',
    'search_rapid_first_request_missing',
    'search_rapid_abort_not_observed',
    'search_rapid_stale_result_repaint',
    'semantic_partial_structured_results_lost',
    'search_album_scope_options',
    'search_album_scope_request',
    'search_all_library_scope_request',
    'search_all_library_scope_context_hidden',
    'search_rich_results_listbox_misuse',
    'search_reduced_motion_media',
    'search_reduced_motion_style',
    'mobile_search_trigger_visible',
    'mobile_search_dialog_semantics',
    'mobile_search_initial_focus',
    'mobile_search_empty_results_hidden',
    'mobile_search_focus_restore'
)) {
    Assert-True ($runner.Contains("'$gate'")) ('v4_chrome_runtime_gate_missing_' + $gate)
}
Assert-True ($runner.Contains('page.emulateMedia({ reducedMotion: ''reduce'' })') -and $runner.Contains("window.matchMedia('(prefers-reduced-motion: reduce)').matches")) 'v4_chrome_reduced_motion_runtime_missing'
Assert-True ($runner.Contains("page.on('requestfailed', onFailed)") -and $runner.Contains('releaseFirstRoute.resolve()') -and $runner.Contains('before === after')) 'v4_chrome_rapid_input_abort_or_stale_runtime_missing'
Assert-True ($runner.Contains('groupedSearchRequest(response.url(), query, ''ALBUM'', albumId)') -and $runner.Contains("scope.selectOption('ALL')") -and $runner.Contains("scope.getAttribute('data-scope-kind') === 'ALL'")) 'v4_chrome_album_scope_runtime_missing'
Assert-True ($runner.Contains("dialog.locator('.global-search-results [role=""option""]')") -and $runner.Contains("dialog.locator('[aria-live=""assertive""]')")) 'v4_chrome_search_announcement_or_result_semantics_missing'
Assert-True ($runner.Contains("dialog.avatar-dialog[open]") -and $runner.Contains("trigger.getAttribute('aria-expanded') === 'true'") -and $runner.Contains("trigger.getAttribute('aria-expanded') === 'false'") -and $runner.Contains('${role}_avatar_menu_entries') -and $runner.Contains('${role}_avatar_focus_restore')) 'v4_chrome_avatar_runtime_missing'
Assert-True ($runner.Contains('.mobile-nav [data-global-search-trigger="true"]') -and $runner.Contains('mobileSearchOverlayCheck(mobile.page)')) 'v4_chrome_mobile_search_runtime_missing'

# The PowerShell wrapper is the only supported entry point: it confines
# credentials, profiles, and screenshots to ignored .codex-work paths, uses
# a CSPRNG run id, refuses path reuse, and removes only its own transient
# profile afterwards.
Assert-True ($wrapper.Contains("'.codex-work\browser\photos-app-v4-chrome'") -and $wrapper.Contains("'.codex-work\screenshots\photos-app-v4-chrome'")) 'v4_chrome_ignored_output_roots_missing'
Assert-True ($wrapper.Contains('[Security.Cryptography.RandomNumberGenerator]::Create()')) 'v4_chrome_random_run_id_missing'
Assert-True ($wrapper.Contains("if (Test-Path -LiteralPath `$path) { throw 'v4_chrome_run_path_not_fresh' }")) 'v4_chrome_run_path_freshness_missing'
Assert-True ($wrapper.Contains('check-ignore --quiet --no-index') -and $wrapper.Contains('git -C $projectRoot ls-files')) 'v4_chrome_private_git_boundary_missing'
Assert-True ($wrapper.Contains('Assert-ClassArchiveOwnerOnlyFileAcl')) 'v4_chrome_credential_acl_gate_missing'
Assert-True ($wrapper.Contains("CLASS_ARCHIVE_V4_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'") -and $wrapper.Contains("CLASS_ARCHIVE_V4_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'")) 'v4_chrome_wrapper_synthetic_origin_missing'
Assert-True ($wrapper.Contains("channel=chrome chrome_product=chrome chrome_version=[0-9.]+")) 'v4_chrome_bounded_wrapper_output_missing'
$mainPathOutput = 'Write-Output (' + "'V4_CHROME_SCREENSHOTS='" + ' + $screenshotDir)'
Assert-True (-not $wrapper.Contains($mainPathOutput)) 'v4_chrome_wrapper_path_output_forbidden'
Assert-True ($wrapper.Contains('Remove-Item -LiteralPath $userDataRoot -Recurse -Force') -and $wrapper.Contains('ReparsePoint')) 'v4_chrome_ephemeral_profile_cleanup_missing'
Assert-True ($wrapper.Contains('v4_chrome_profile_cleanup_boundary') -and $wrapper.Contains('v4_chrome_profile_cleanup_failed') -and $wrapper.Contains('-ErrorAction Stop')) 'v4_chrome_profile_cleanup_must_fail_closed'
Assert-True ($wrapper.Contains('V4_CHROME_QA_COMPLETE=PASS')) 'v4_chrome_post_finally_completion_missing'
$mainFinally = $wrapper.LastIndexOf('} finally {', [StringComparison]::Ordinal)
$mainCompletion = $wrapper.IndexOf("Write-Output 'V4_CHROME_QA_COMPLETE=PASS'", [StringComparison]::Ordinal)
Assert-True ($mainFinally -ge 0 -and $mainCompletion -gt $mainFinally) 'v4_chrome_completion_must_follow_finally'
$privateDriveRoot = 'M' + [char]58 + [char]92
Assert-True (-not $wrapper.Contains('8191') -and -not $wrapper.Contains('8190') -and -not $wrapper.Contains($privateDriveRoot)) 'v4_chrome_wrapper_private_target_forbidden'

# Keep the evidence boundary clear: the dedicated V4 Chrome run covers its
# stated contract but cannot be cited as Era-upload, Viewer, or MediaGuard
# proof until those dedicated Chrome modules exist.
foreach ($boundary in @('Viewer', 'MediaGuard GET/HEAD/Range', 'Era-upload')) {
    Assert-True ($docs.Contains($boundary)) ('v4_chrome_docs_evidence_boundary_missing_' + ($boundary -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($docs.Contains("channel: 'chrome'") -and $docs.Contains("never uses the user's Chrome profile") -and $docs.Contains('CDP-reported product and version')) 'v4_chrome_docs_channel_or_version_boundary_missing'
foreach ($docNeedle in @('Ctrl+K', 'browser Back', 'ALBUM:<opaque-id>', 'delayed first grouped request', 'semantic outage injection', 'account-avatar menu', 'prefers-reduced-motion')) {
    Assert-True ($docs.Contains($docNeedle)) ('v4_chrome_docs_search_or_avatar_evidence_missing_' + ($docNeedle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_CHROME_QA_PROTOCOL=PASS assertions=$assertions"
