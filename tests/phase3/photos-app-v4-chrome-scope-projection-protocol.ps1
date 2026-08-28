[CmdletBinding()]
param()

# Static-only contract for the independent synthetic V4 projection browser
# runner. It neither starts a browser/container nor opens a credential file.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-scope-projection.mjs'
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-scope-projection.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-chrome-scope-projection.md'
$policyPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Gateway\GatewayPolicy.php'
$gatewayPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\Gateway\GatewayService.php'
$serverPath = Join-Path $projectRoot 'infra\immich-spike\web-compat\server.mjs'
$unknownFixturePath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-scope-unknown-fixture.php'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Source $runnerPath 'v4_scope_runner_missing'
$wrapper = Read-Source $wrapperPath 'v4_scope_wrapper_missing'
$docs = Read-Source $docsPath 'v4_scope_docs_missing'
$policy = Read-Source $policyPath 'v4_scope_policy_missing'
$gateway = Read-Source $gatewayPath 'v4_scope_gateway_missing'
$server = Read-Source $serverPath 'v4_scope_bff_missing'
$unknownFixture = Read-Source $unknownFixturePath 'v4_scope_unknown_fixture_missing'
$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'v4_scope_wrapper_parse_invalid'
$node = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
Assert-True (Test-Path -LiteralPath $node -PathType Leaf) 'v4_scope_node_parse_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'v4_scope_runner_parse_invalid'

# The scope gate must be a real, fresh Google Chrome Stable session. It may
# not inherit a daily profile, use bundled Chromium, or reach a private/public
# endpoint while it is collecting synthetic evidence.
Assert-True ($runner.Contains("channel: 'chrome'") -and $runner.Contains('headless: false') -and $runner.Contains('chromium.launchPersistentContext')) 'v4_scope_chrome_stable_missing'
Assert-True ($runner.Contains('context.newCDPSession(page)') -and $runner.Contains("session.send('Browser.getVersion')") -and $runner.Contains('/^Chrome\/(\d+(?:\.\d+){1,4})$/')) 'v4_scope_chrome_version_proof_missing'
Assert-True ($runner.Contains("['8090', '8091']") -and $runner.Contains("url.hostname === '127.0.0.1'") -and $runner.Contains('route.abort()')) 'v4_scope_loopback_allowlist_missing'
Assert-True (-not $runner.Contains('8191') -and -not $runner.Contains('8190') -and -not $runner.Contains('0.0.0.0') -and -not $runner.Contains('executablePath')) 'v4_scope_private_or_unbranded_browser_forbidden'
Assert-True ($runner.Contains("document.environment === 'synthetic'") -and $runner.Contains("'anonymous,classmate,family,teacher'") -and $runner.Contains('familyDeniedPhotoId') -and $runner.Contains('readScopeTruth') -and $runner.Contains('unknownPhotoId')) 'v4_scope_synthetic_credential_contract_missing'
Assert-True ($runner.Contains("settings.requirePeople") -and $runner.Contains("'scope_people_fixture_missing'") -and $runner.Contains("'scope_family_people_fixture_missing'")) 'v4_scope_people_nonempty_gate_missing'

# Browser payload evidence must cover the entire Phase A read surface, not
# only a timeline count. Exact known-LIVING UUID checks defend cards, counts,
# covers, detail routes and grouped search from a client-side hide regression.
foreach ($endpoint in @(
    '/api/class-archive/timeline${suffix}',
    "'/api/class-archive/collections/home'",
    "'/api/class-archive/collections/pins'",
    "'/api/class-archive/albums'",
    "'/api/people'",
    "'/api/class-archive/spotlight'",
    "'/api/class-archive/search/suggestions?q=%E8%AE%B0%E5%BF%86'"
)) {
    Assert-True ($runner.Contains($endpoint)) ('v4_scope_endpoint_missing_' + ($endpoint -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains('/api/class-archive/search/grouped?q=') -and $runner.Contains('&limit=120') -and $runner.Contains('/api/assets/${photoId}')) 'v4_scope_endpoint_missing_api_class_archive_search_grouped'
Assert-True (-not $runner.Contains('/api/class-archive/photos')) 'v4_scope_internal_photo_route_forbidden'
Assert-True ($server.Contains("if (url.pathname === '/api/people')") -and $server.Contains('const assetMatch = /^\/api\/assets') -and $server.Contains("if (url.pathname === '/api/class-archive/timeline')")) 'v4_scope_bff_route_crosscheck_missing'
Assert-True ($runner.Contains('assertCollectionSnapshot') -and $runner.Contains('assertPins') -and $runner.Contains('assertAlbumList') -and $runner.Contains('assertPeopleList') -and $runner.Contains('assertSpotlight') -and $runner.Contains('assertGroupedSearch') -and $runner.Contains('assertSuggestions') -and $runner.Contains('assertUnknownArchiveAggregate')) 'v4_scope_projection_surface_assertions_missing'
Assert-True ($runner.Contains('assertNoKnownLiving') -and $runner.Contains('family_known_living_photo_denied') -and $runner.Contains('family_denied_photo_must_be_living')) 'v4_scope_known_living_negative_evidence_missing'
Assert-True ($runner.Contains('family_heritage_catalog_exact') -and $runner.Contains('full_catalog_exact') -and $runner.Contains("'HERITAGE_ONLY'") -and $runner.Contains("'FULL'")) 'v4_scope_full_vs_heritage_exactness_missing'
Assert-True ($runner.Contains('family_person_count_scope') -and $runner.Contains('person_detail_scope') -and $runner.Contains('album_detail_scope') -and $runner.Contains('person_detail_unknown') -and $runner.Contains('album_detail_unknown')) 'v4_scope_person_and_album_detail_scope_missing'
Assert-True ($runner.Contains('coverPhotoId') -and $runner.Contains('photoCount') -and $runner.Contains('photoIds')) 'v4_scope_cover_count_membership_missing'
Assert-True ($runner.Contains('unknownArchivePhotoIds') -and $runner.Contains("q=%E6%97%A5%E6%9C%9F%E6%9C%AA%E7%9F%A5") -and $runner.Contains('assertUnknownArchiveAggregate')) 'v4_scope_archive_time_aggregate_exactness_missing'

# The synthetic wrapper retains the immutable 72/72/8 baseline before and
# after its tightly scoped UNKNOWN-era fault. It may not start/stop Docker,
# and the fixture restores exact root memberships before the final baseline.
Assert-True ($wrapper.Contains("'http://127.0.0.1:8090/'") -and $wrapper.Contains("'http://127.0.0.1:8091/'")) 'v4_scope_synthetic_origins_missing'
Assert-True ($wrapper.Contains('Assert-SyntheticBaseline') -and $wrapper.Contains("'v4_scope_baseline_before_failed'") -and $wrapper.Contains("'v4_scope_baseline_after_failed'") -and $wrapper.Contains('images=72 originals=72 multi_album=8')) 'v4_scope_baseline_bracket_missing'
Assert-True ($wrapper.Contains("CLASS_ARCHIVE_V4_SCOPE_REQUIRE_PEOPLE = '1'") -and $wrapper.Contains('CLASS_ARCHIVE_V4_SCOPE_TRUTH_FILE') -and $wrapper.Contains('Update-ScopeTruth') -and $wrapper.Contains('Assert-ClassArchiveOwnerOnlyFileAcl') -and $wrapper.Contains('check-ignore --quiet --no-index')) 'v4_scope_private_credential_guard_missing'
Assert-True ($wrapper.Contains('[Security.Cryptography.RandomNumberGenerator]::Create()') -and $wrapper.Contains("'.codex-work\browser\photos-app-v4-chrome-scope-projection'") -and $wrapper.Contains("'.codex-work\screenshots\photos-app-v4-scope-projection'")) 'v4_scope_isolated_evidence_path_missing'
Assert-True ($wrapper.Contains('photos-app-v4-scope-unknown-fixture.php') -and $wrapper.Contains("'prepare',`$run,`$familyDeniedPhotoId") -and $wrapper.Contains("'cleanup',`$run") -and $wrapper.Contains('scopeFixtureAttempted') -and $wrapper.Contains("CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE=1")) 'v4_scope_unknown_fixture_lifecycle_missing'
Assert-True ($wrapper.Contains('v4_scope_profile_cleanup_boundary') -and $wrapper.Contains('v4_scope_profile_cleanup_failed') -and $wrapper.Contains('V4_SCOPE_PROJECTION_COMPLETE=PASS')) 'v4_scope_post_finally_completion_missing'
$scopeFinally = $wrapper.LastIndexOf('finally {', [StringComparison]::Ordinal)
$scopeCompletion = $wrapper.IndexOf("Write-Output 'V4_SCOPE_PROJECTION_COMPLETE=PASS'", [StringComparison]::Ordinal)
Assert-True ($scopeFinally -ge 0 -and $scopeCompletion -gt $scopeFinally) 'v4_scope_completion_must_follow_finally'
Assert-True (-not $wrapper.Contains(' up ') -and -not $wrapper.Contains(' down ') -and -not $wrapper.Contains('8191') -and -not $wrapper.Contains('8190')) 'v4_scope_private_runtime_or_lifecycle_forbidden'

# UNKNOWN must remain an authorization failure, not a Family fallback. The
# controlled synthetic fault removes a single LIVING-root association and
# restores it exactly after Chrome verifies every role's denial.
Assert-True ($policy.Contains("|| `$photo->era() === null") -and $policy.Contains('return false;') -and $policy.Contains("Access::ROLE_FAMILY => `$photo->era() === 'HERITAGE'")) 'v4_scope_unknown_family_fail_closed_policy_missing'
Assert-True ($gateway.Contains('function recheckCollectionSnapshotItem') -and $gateway.Contains('if ($this->mediaCandidate($photoId) !== null)') -and $gateway.Contains("Access::ROLE_FAMILY => ReadProjectionStore::SCOPE_HERITAGE")) 'v4_scope_gateway_acl_recheck_missing'
Assert-True ($unknownFixture.Contains("getenv('CLASS_ARCHIVE_V4_SCOPE_UNKNOWN_FIXTURE') !== '1'") -and $unknownFixture.Contains('class-archive-living') -and $unknownFixture.Contains('v4scopeAssertSyntheticBaseline') -and $unknownFixture.Contains('v4scopeRemoveLivingAssociations') -and $unknownFixture.Contains('v4scopeRestoreLivingAssociations') -and $unknownFixture.Contains('ReadProjectionBuilder::rebuild')) 'v4_scope_unknown_fixture_fail_closed_missing'
Assert-True ($unknownFixture.Contains("'/tmp/class-archive-v4-scope-'") -and $unknownFixture.Contains('v4scopeAcquireGlobalLock') -and $unknownFixture.Contains('v4scopeReleaseGlobalLock') -and $unknownFixture.Contains('v4scopeReplaceState') -and $unknownFixture.Contains('living_associations') -and $unknownFixture.Contains('unknown_archive_photo_ids') -and $unknownFixture.Contains("fopen(`$path, 'x')") -and -not $unknownFixture.Contains('delete_elements(')) 'v4_scope_unknown_fixture_boundary_missing'
foreach ($term in @('STATIC', 'BROWSER_E2E_TESTED', 'UNKNOWN', 'controlled synthetic People fixture', '72 / 72 / 8', 'does not start Docker', 'exact LIVING-root memberships')) {
    Assert-True ($docs.Contains($term)) ('v4_scope_docs_boundary_missing_' + ($term -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_SCOPE_PROJECTION_PROTOCOL=PASS assertions=$assertions"
