[CmdletBinding()]
param()

# Static-only proof for the Chrome process-start egress guard. It never starts
# Chrome or Docker and never reads a credential, runtime, or private endpoint.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$guardPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-localhost-guard.mjs'
$runnerPaths = @(
    (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-qa.mjs'),
    (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-deep-qa.mjs'),
    (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-scope-projection.mjs'),
    (Join-Path $projectRoot 'tests\phase3\photos-app-v4-chrome-upload-lifecycle.mjs')
)
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

Assert-True (Test-Path -LiteralPath $guardPath -PathType Leaf) 'v4_chrome_localhost_guard_missing'
foreach ($path in $runnerPaths) { Assert-True (Test-Path -LiteralPath $path -PathType Leaf) 'v4_chrome_localhost_guard_runner_missing' }
$guard = [IO.File]::ReadAllText($guardPath)

$node = (Get-Command node -ErrorAction SilentlyContinue).Source
Assert-True (-not [string]::IsNullOrWhiteSpace($node)) 'v4_chrome_localhost_guard_node_unavailable'
& $node --check $guardPath
Assert-True ($LASTEXITCODE -eq 0) 'v4_chrome_localhost_guard_parse_invalid'

Assert-True ($guard.Contains('CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS') -and $guard.Contains('Object.freeze')) 'v4_chrome_localhost_guard_export_missing'
Assert-True ($guard.Contains('--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE localhost, EXCLUDE 127.0.0.1, EXCLUDE ::1') -and $guard.Contains('--host-resolver-retry-attempts=0')) 'v4_chrome_localhost_guard_resolver_fail_closed_missing'
Assert-True ($guard.Contains('--proxy-server=http://127.0.0.1:9') -and $guard.Contains('--proxy-bypass-list=localhost,127.0.0.1,::1')) 'v4_chrome_localhost_guard_blackhole_proxy_missing'
Assert-True ($guard.Contains('--disable-quic') -and $guard.Contains('--disable-extensions') -and $guard.Contains('--webrtc-ip-handling-policy=disable_non_proxied_udp')) 'v4_chrome_localhost_guard_udp_or_extension_boundary_missing'
foreach ($forbidden in @('direct://', '0.0.0.0', '8191', '8190', 'proxy.pac')) {
    Assert-True (-not $guard.Contains($forbidden)) ('v4_chrome_localhost_guard_forbidden_token_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

foreach ($path in $runnerPaths) {
    $runner = [IO.File]::ReadAllText($path)
    Assert-True ($runner.Contains("from './photos-app-v4-chrome-localhost-guard.mjs'")) ('v4_chrome_localhost_guard_import_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
    Assert-True ($runner.Contains('...CHROME_SYNTHETIC_LOCALHOST_ONLY_LAUNCH_ARGS')) ('v4_chrome_localhost_guard_spread_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
    Assert-True ($runner.Contains('chromium.launchPersistentContext') -and $runner.Contains("context.route('**/*'")) ('v4_chrome_localhost_guard_process_and_context_layers_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
    & $node --check $path
    Assert-True ($LASTEXITCODE -eq 0) ('v4_chrome_localhost_guard_runner_parse_invalid_' + [IO.Path]::GetFileNameWithoutExtension($path))
}

Write-Output "PHOTOS_APP_V4_CHROME_LOCALHOST_GUARD_PROTOCOL=PASS assertions=$assertions"
