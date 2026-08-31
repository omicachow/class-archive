[CmdletBinding()]
param()

# Public-safe static boundary only. It does not start Docker/Chrome, create a
# fixture, read the private database, or accept credentials.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$presenterPath = Join-Path $projectRoot 'plugins\ClassIdentity\src\AnonymousPresenter.php'
$brokerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-teacher-fixture-broker.php'
$publicPath = Join-Path $projectRoot 'plugins\ClassIdentity\public.php'
$assertions = 0

function Assert-Visibility([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-Visibility $Text.Contains($Needle) $Code
}
function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-Visibility (-not $Text.Contains($Needle)) $Code
}

foreach ($path in @($presenterPath, $brokerPath, $publicPath)) {
    Assert-Visibility (Test-Path -LiteralPath $path -PathType Leaf) ('fixture_visibility_source_missing_' + [IO.Path]::GetFileName($path))
}

$presenter = [IO.File]::ReadAllText($presenterPath)
$broker = [IO.File]::ReadAllText($brokerPath)
$public = [IO.File]::ReadAllText($publicPath)

# The fixture is an exact reserved Teacher roster, never a broad prefix that
# could accidentally suppress a legitimate future account. Normal discovery
# calls share the same hidden-user projection; SYSTEM_ADMIN retains recovery
# visibility through the separate control plane.
foreach ($needle in @(
    "private const HIDDEN_LOCAL_TEACHER_FIXTURE_ROSTER = 'FQA-T-3E2F1A94B0C74D81952E6F0A';",
    "`$repository->table('identity')",
    'i.`identity_type` = ''TEACHER''',
    'self::HIDDEN_LOCAL_TEACHER_FIXTURE_ROSTER',
    'public static function filterOrdinaryUserDiscovery',
    'public static function guardHiddenUserSearch',
    'public static function filterSearchUploaderChoices',
    'Access::isActiveSystemAdmin()',
    'private static function hiddenPiwigoUserIds()'
)) {
    Assert-Contains $presenter $needle ('fixture_visibility_guard_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-NotContains $presenter "LIKE 'FQA-%'" 'fixture_visibility_broad_prefix_forbidden'
Assert-NotContains $presenter "str_starts_with((string) `$row['roster_code'], 'FQA-')" 'fixture_visibility_broad_runtime_prefix_forbidden'

# The Teacher browser fixture is browse-only: it cannot attach its identity to
# any public projection via comments, uploads or Spotlight during its lease.
foreach ($forbidden in @(
    'setInputFiles', '/api/class-archive/member-upload', 'comment/create',
    'comment/reply', 'spotlight', 'writeFileSync', 'fetch('
)) {
    Assert-NotContains $broker $forbidden ('fixture_visibility_broker_write_surface_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# There is no normal member-directory route. The only public identity route is
# the current principal's own identity; identity administration stays behind
# the SYSTEM_ADMIN control plane.
foreach ($needle in @(
    "private const ROUTE_MY_IDENTITY = 'my';",
    'self::ROUTE_MY_IDENTITY =>',
    'if ($route === self::ROUTE_MY_IDENTITY && $isGuest)',
    'private static function loadMyIdentity(): array'
)) {
    Assert-Contains $public $needle ('fixture_visibility_public_route_guard_missing_' + ($needle -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-NotContains $public 'ROUTE_MEMBER_DIRECTORY' 'fixture_visibility_member_directory_route_forbidden'
Assert-NotContains $public 'ROUTE_IDENTITY_SEARCH' 'fixture_visibility_identity_search_route_forbidden'

Write-Output "PRIVATE_E2E_TEACHER_FIXTURE_VISIBILITY_PROTOCOL=PASS assertions=$assertions evidence=STATIC_EXACT_HIDDEN_DISCOVERY_GUARD"
