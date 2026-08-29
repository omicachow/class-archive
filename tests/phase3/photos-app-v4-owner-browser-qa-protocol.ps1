[CmdletBinding()]
param()

# Static contract for the owner-private V4 browser harness. It deliberately
# does not launch Chrome, Docker, WSL, an HTTP request, or the temporary-role
# provisioner. Parsing and source inspection are enough to reject accidental
# broadening of the harness' local/private boundary.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-browser-qa.ps1'
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-browser-qa.mjs'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-owner-browser-qa.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}
function Assert-Contains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True $Text.Contains($Needle) $Code
}
function Assert-NotContains([string]$Text, [string]$Needle, [string]$Code) {
    Assert-True (-not $Text.Contains($Needle)) $Code
}

foreach ($path in @($wrapperPath, $runnerPath, $docsPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('v4_owner_browser_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}

$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$errors)
Assert-True ($errors.Count -eq 0) 'v4_owner_browser_wrapper_parse_invalid'

$node = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
Assert-True (Test-Path -LiteralPath $node -PathType Leaf) 'v4_owner_browser_node_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'v4_owner_browser_runner_parse_invalid'

$wrapper = [IO.File]::ReadAllText($wrapperPath)
$runner = [IO.File]::ReadAllText($runnerPath)
$docs = [IO.File]::ReadAllText($docsPath)

Assert-Contains $wrapper '[switch]$ProvisionTemporaryRoles' 'v4_owner_browser_explicit_provision_switch_missing'
Assert-Contains $wrapper 'explicit_temporary_role_provisioning_required' 'v4_owner_browser_explicit_provision_guard_missing'
Assert-Contains $wrapper 'New-ClassArchiveSystemAdminSession' 'v4_owner_browser_admin_lease_missing'
Assert-Contains $wrapper 'Remove-ClassArchiveSystemAdminSession' 'v4_owner_browser_admin_lease_revoke_missing'
Assert-Contains $wrapper 'Set-ClassArchiveOwnerOnlyFileAcl' 'v4_owner_browser_secret_acl_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\browser\photos-app-v4-owner' 'v4_owner_browser_profile_root_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\screenshots\photos-app-v4' 'v4_owner_browser_screenshot_root_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\runtime\photos-app-v4-owner' 'v4_owner_browser_runtime_root_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8190/'" 'v4_owner_browser_core_origin_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8191/'" 'v4_owner_browser_photo_origin_missing'
$privateDriveRoot = 'M' + [char]58 + [char]92
$windowsUserRoot = 'C' + [char]58 + [char]92 + 'Users' + [char]92
foreach ($forbidden in @('docker compose up','docker compose down','docker compose pull','Start-Process',$privateDriveRoot,$windowsUserRoot)) {
    Assert-NotContains $wrapper $forbidden ('v4_owner_browser_wrapper_forbidden_' + (($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant()))
}

Assert-Contains $runner 'chromium.launchPersistentContext' 'v4_owner_browser_persistent_context_missing'
Assert-Contains $runner "channel: 'chrome'" 'v4_owner_browser_chrome_stable_channel_missing'
Assert-Contains $runner 'headless: false' 'v4_owner_browser_headed_chrome_missing'
Assert-Contains $runner "context.route('**/*'" 'v4_owner_browser_context_network_guard_missing'
Assert-Contains $runner 'CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS' 'v4_owner_browser_process_network_guard_missing'
Assert-Contains $runner 'http://127.0.0.1' 'v4_owner_browser_loopback_only_missing'
Assert-Contains $runner 'create_classmate' 'v4_owner_browser_classmate_real_flow_missing'
Assert-Contains $runner 'create_teacher' 'v4_owner_browser_teacher_real_flow_missing'
Assert-Contains $runner 'issue_family_invitation' 'v4_owner_browser_family_real_flow_missing'
Assert-Contains $runner 'accept_family' 'v4_owner_browser_family_claim_real_flow_missing'
Assert-Contains $runner 'activate_anonymous' 'v4_owner_browser_anonymous_real_flow_missing'
Assert-Contains $runner 'freeze_identity' 'v4_owner_browser_exact_cleanup_missing'
Assert-Contains $runner 'finally' 'v4_owner_browser_finally_cleanup_missing'
Assert-Contains $runner "state.role === expectedRole" 'v4_owner_browser_role_scope_probe_missing'
Assert-Contains $runner '/api/assets/' 'v4_owner_browser_mediaguard_media_path_missing'
Assert-Contains $runner "name: '打开评论'" 'v4_owner_browser_viewer_comment_probe_missing'
Assert-Contains $runner "name: '搜索照片'" 'v4_owner_browser_search_probe_missing'
Assert-NotContains $runner 'chromium.launch({' 'v4_owner_browser_nonpersistent_launch_present'
foreach ($forbidden in @($privateDriveRoot,'0.0.0.0','http://localhost','https://','source_root','sourcePath','fs.cp','fs.copyFile','docker')) {
    Assert-NotContains $runner $forbidden ('v4_owner_browser_runner_forbidden_' + (($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant()))
}

Assert-Contains $docs 'Google Chrome Stable' 'v4_owner_browser_docs_browser_boundary_missing'
Assert-Contains $docs '-ProvisionTemporaryRoles' 'v4_owner_browser_docs_explicit_mutation_boundary_missing'
Assert-Contains $docs 'does not import, copy, or modify photo source data' 'v4_owner_browser_docs_source_boundary_missing'
Assert-Contains $docs 'freezes only the two identities created by its own run' 'v4_owner_browser_docs_cleanup_boundary_missing'
Assert-Contains $docs 'not evidence of a known-LIVING private URL denial' 'v4_owner_browser_docs_living_evidence_boundary_missing'

Write-Output "PHOTOS_APP_V4_OWNER_BROWSER_PROTOCOL=PASS assertions=$assertions"
