[CmdletBinding()]
param()

# Static public-safe contract. It launches no browser, provisions no account,
# reads no ignored evidence and makes no HTTP request.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-performance.mjs'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-performance.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-chrome-performance.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Source $runnerPath 'v4_chrome_performance_runner_missing'
$wrapper = Read-Source $wrapperPath 'v4_chrome_performance_wrapper_missing'
$docs = Read-Source $docsPath 'v4_chrome_performance_docs_missing'

Assert-True ($runner.Contains("const MEASURED_SAMPLES = 7") -and $runner.Contains("const WARMUP_SAMPLES = 2")) 'v4_chrome_performance_sample_contract_missing'
Assert-True ($runner.Contains('values.sort') -or $runner.Contains("[...values].sort((a, b) => a - b)")) 'v4_chrome_performance_median_sort_missing'
Assert-True ($runner.Contains('sorted[Math.floor(sorted.length / 2)]')) 'v4_chrome_performance_p50_missing'
foreach ($metric in @('SEARCH_OVERLAY_OPEN_P50_MS','SEARCH_SUGGESTIONS_VISIBLE_P50_MS','STRUCTURED_SEARCH_P50_MS','COLLECTIONS_HOME_WARM_P50_MS')) {
    Assert-True ($runner.Contains($metric) -and $wrapper.Contains($metric) -and $docs.Contains($metric)) ('v4_chrome_performance_metric_missing_' + $metric.ToLowerInvariant())
}
foreach ($limit in @('SEARCH_OVERLAY_OPEN_P50_MS: 100','SEARCH_SUGGESTIONS_VISIBLE_P50_MS: 150','STRUCTURED_SEARCH_P50_MS: 300','COLLECTIONS_HOME_WARM_P50_MS: 400')) {
    Assert-True ($runner.Contains($limit)) ('v4_chrome_performance_limit_missing_' + ($limit -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Assert-True ($runner.Contains('chromium.launchPersistentContext(profile') -and $runner.Contains("channel: 'chrome'") -and $runner.Contains('headless: false')) 'v4_chrome_performance_chrome_stable_missing'
Assert-True (-not $runner.Contains('executablePath')) 'v4_chrome_performance_executable_path_forbidden'
Assert-True ($runner.Contains("check(!fs.existsSync(profile), 'profile_not_fresh')")) 'v4_chrome_performance_fresh_profile_missing'
Assert-True ($runner.Contains("serviceWorkers: 'block'") -and $runner.Contains('acceptDownloads: false')) 'v4_chrome_performance_context_hardening_missing'
Assert-True ($runner.Contains('CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS') -and $runner.Contains("['8090', '8091']") -and $runner.Contains('route.abort()')) 'v4_chrome_performance_localhost_guard_missing'
Assert-True (-not $runner.Contains('8190') -and -not $runner.Contains('8191') -and -not $runner.Contains('8290') -and -not $runner.Contains('8291')) 'v4_chrome_performance_private_origin_forbidden'
Assert-True ($runner.Contains("document?.version === 1 && document.environment === 'synthetic'") -and $runner.Contains("'anonymous,classmate,family,teacher'")) 'v4_chrome_performance_fixture_contract_missing'
Assert-True ($runner.Contains("newCDPSession(page)") -and $runner.Contains("session.send('Browser.getVersion')")) 'v4_chrome_performance_cdp_version_missing'

Assert-True ($runner.Contains("if (new URL(request.url()).pathname === '/api/class-archive/timeline') fullTimelineRequests += 1") -and $runner.Contains("check(fullTimelineRequests === 0, 'home_full_timeline_preload')")) 'v4_chrome_performance_full_library_preload_guard_missing'
Assert-True ($runner.IndexOf("await page.waitForLoadState('networkidle'", [StringComparison]::Ordinal) -lt $runner.IndexOf("page.on('request', listener)", [StringComparison]::Ordinal)) 'v4_chrome_performance_library_request_settle_missing'
Assert-True ($runner.Contains('dialog.locator(''[role="listbox"]'')') -and $runner.Contains('dialog.locator(''.search-structured-group, .search-photo-grid'')')) 'v4_chrome_performance_visible_milestone_missing'
Assert-True ($runner.Contains('page.waitForResponse') -and $runner.Contains("target.pathname === '/api/class-archive/search/grouped'")) 'v4_chrome_performance_structured_network_proof_missing'
Assert-True ($runner.Contains("homeFullTimelineRequests: 0") -and $runner.Contains("violations.length === 0 ? 'PASS' : 'FAIL'")) 'v4_chrome_performance_evidence_shape_missing'
Assert-True ($runner.IndexOf('for (const name of Object.keys(LIMITS)) process.stdout.write', [StringComparison]::Ordinal) -lt $runner.IndexOf('if (violations.length !== 0) fail(', [StringComparison]::Ordinal)) 'v4_chrome_performance_failure_metrics_missing'
Assert-True (-not $runner.Contains('familyDeniedPhotoId') -and -not $runner.Contains('source_reference') -and -not $runner.Contains('absolutePath')) 'v4_chrome_performance_private_identifier_output_forbidden'

Assert-True ($wrapper.Contains("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$projectRoot -Purpose 'chrome-performance'")) 'v4_chrome_performance_shared_lease_missing'
$leaseEnter = $wrapper.IndexOf("Enter-V4SyntheticPhaseAMutationLease -ProjectRoot `$projectRoot -Purpose 'chrome-performance'", [StringComparison]::Ordinal)
$prepare = $wrapper.IndexOf('Invoke-SyntheticFixture -Action prepare', [StringComparison]::Ordinal)
$rotate = $wrapper.IndexOf('Invoke-SyntheticFixture -Action rotate -CredentialFile $credentialPath', [StringComparison]::Ordinal)
$leaseExit = $wrapper.IndexOf('Exit-V4SyntheticPhaseAMutationLease -Lease $lease', [StringComparison]::Ordinal)
Assert-True ($leaseEnter -ge 0 -and $prepare -gt $leaseEnter -and $rotate -gt $prepare -and $leaseExit -gt $rotate) 'v4_chrome_performance_fixture_lifecycle_order_invalid'
Assert-True ($wrapper.Contains("'.codex-work\browser\photos-app-v4-chrome-performance'") -and $wrapper.Contains("'.codex-work\evidence\photos-app-v4-chrome-performance'")) 'v4_chrome_performance_ignored_roots_missing'
Assert-True ($wrapper.Contains("if ((Test-Path -LiteralPath `$profilePath) -or (Test-Path -LiteralPath `$evidenceDirectory))")) 'v4_chrome_performance_fresh_path_predicate_missing'
Assert-True ($wrapper.Contains('check-ignore --quiet --no-index') -and $wrapper.Contains('git -C $projectRoot ls-files')) 'v4_chrome_performance_git_boundary_missing'
Assert-True ($wrapper.Contains('Assert-ClassArchiveOwnerOnlyFileAcl -Path $credentialPath')) 'v4_chrome_performance_credential_acl_missing'
Assert-True ($wrapper.Contains('Remove-Item -LiteralPath $profilePath -Recurse -Force -ErrorAction Stop') -and $wrapper.Contains('ReparsePoint')) 'v4_chrome_performance_profile_cleanup_missing'
Assert-True ($wrapper.Contains('V4_CHROME_PERFORMANCE_COMPLETE=PASS credential=ROTATED profile=REMOVED evidence=IGNORED')) 'v4_chrome_performance_terminal_record_missing'
Assert-True ($wrapper.Contains('if (-not $runPassed) { exit 1 }') -and -not $wrapper.Contains("throw 'v4_chrome_performance_failed'")) 'v4_chrome_performance_bounded_failure_exit_missing'
Assert-True (-not $wrapper.Contains('Write-Output $credentialPath') -and -not $wrapper.Contains('Write-Output $evidencePath')) 'v4_chrome_performance_path_output_forbidden'
Assert-True ($wrapper.Contains("CLASS_ARCHIVE_V4_PERF_PIWIGO_ORIGIN = 'http://127.0.0.1:8090/'") -and $wrapper.Contains("CLASS_ARCHIVE_V4_PERF_PHOTO_ORIGIN = 'http://127.0.0.1:8091/'")) 'v4_chrome_performance_synthetic_origin_missing'
Assert-True (-not $wrapper.Contains('8190') -and -not $wrapper.Contains('8191') -and -not $wrapper.Contains('8290') -and -not $wrapper.Contains('8291')) 'v4_chrome_performance_private_wrapper_forbidden'

foreach ($boundary in @('Google Chrome Stable','seven measured samples','never requests the full timeline','rotates the temporary fixture password','ignored local evidence')) {
    Assert-True ($docs.Contains($boundary)) ('v4_chrome_performance_docs_boundary_missing_' + ($boundary -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_CHROME_PERFORMANCE_PROTOCOL=PASS assertions=$assertions"
