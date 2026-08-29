[CmdletBinding()]
param()

# Static and no-switch safety contract for the owner-private existing-fixture
# Chrome harness. It must not launch Docker/Chrome or make HTTP requests.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$wrapperPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-browser-qa.ps1'
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-browser-qa.mjs'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-owner-browser-qa.md'
$provisionerPath = Join-Path $projectRoot 'tests\fixtures\provision-access-users.php'
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

foreach ($path in @($wrapperPath, $runnerPath, $docsPath, $provisionerPath)) {
    Assert-True (Test-Path -LiteralPath $path -PathType Leaf) ('owner_existing_fixture_file_missing_' + [IO.Path]::GetFileNameWithoutExtension($path))
}

$tokens = $null
$errors = $null
[void][Management.Automation.Language.Parser]::ParseFile($wrapperPath, [ref]$tokens, [ref]$errors)
Assert-True ($errors.Count -eq 0) 'owner_existing_fixture_wrapper_parse_invalid'
$node = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
Assert-True (Test-Path -LiteralPath $node -PathType Leaf) 'owner_existing_fixture_node_unavailable'
& $node --check $runnerPath
Assert-True ($LASTEXITCODE -eq 0) 'owner_existing_fixture_runner_parse_invalid'

$wrapper = [IO.File]::ReadAllText($wrapperPath)
$runner = [IO.File]::ReadAllText($runnerPath)
$docs = [IO.File]::ReadAllText($docsPath)
$provisioner = [IO.File]::ReadAllText($provisionerPath)

Assert-Contains $wrapper '[switch]$ConfirmExistingFixtureCredentialRotation' 'owner_existing_fixture_explicit_switch_missing'
Assert-Contains $wrapper 'explicit_fixture_credential_rotation_confirmation_required' 'owner_existing_fixture_no_switch_guard_missing'
Assert-Contains $wrapper 'infra/private-full/.env.piwigo.owner' 'owner_existing_fixture_full_v3_env_missing'
Assert-Contains $wrapper 'class_archive_private_full_v3_piwigo' 'owner_existing_fixture_full_v3_project_missing'
Assert-Contains $wrapper '/workspace/tests/fixtures/provision-access-users.php' 'owner_existing_fixture_trusted_provisioner_missing'
Assert-Contains $wrapper 'fixture_final_rotation' 'owner_existing_fixture_final_rotation_missing'
Assert-Contains $wrapper 'credentials=unknown' 'owner_existing_fixture_unknown_final_secret_missing'
Assert-Contains $wrapper 'Set-ClassArchiveOwnerOnlyFileAcl' 'owner_existing_fixture_owner_acl_missing'
Assert-Contains $wrapper '[string[]]@($lines | ForEach-Object { [string]$_ })' 'owner_existing_fixture_empty_native_output_safe_join_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\browser\photos-app-v4-owner-existing-fixtures' 'owner_existing_fixture_profile_root_missing'
Assert-Contains $wrapper '.codex-work\private-real-qa\screenshots\photos-app-v4' 'owner_existing_fixture_screenshot_root_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8190/'" 'owner_existing_fixture_core_origin_missing'
Assert-Contains $wrapper "'http://127.0.0.1:8191/'" 'owner_existing_fixture_photo_origin_missing'
Assert-NotContains $wrapper 'New-ClassArchiveSystemAdminSession' 'owner_existing_fixture_admin_session_forbidden'
Assert-NotContains $wrapper 'ProvisionTemporaryRoles' 'owner_existing_fixture_temporary_identity_switch_forbidden'
Assert-NotContains $wrapper 'docker compose up' 'owner_existing_fixture_compose_up_forbidden'
Assert-NotContains $wrapper 'docker compose down' 'owner_existing_fixture_compose_down_forbidden'
Assert-NotContains $wrapper 'docker volume' 'owner_existing_fixture_volume_mutation_forbidden'
Assert-NotContains $wrapper 'Start-Process' 'owner_existing_fixture_background_process_forbidden'

foreach ($role in @('classmate', 'family', 'teacher', 'anonymous')) {
    Assert-Contains $runner ("fixture-" + $role) ('owner_existing_fixture_role_missing_' + $role)
}
Assert-Contains $runner 'chromium.launchPersistentContext' 'owner_existing_fixture_persistent_context_missing'
Assert-Contains $runner "channel: 'chrome'" 'owner_existing_fixture_chrome_channel_missing'
Assert-Contains $runner 'headless: false' 'owner_existing_fixture_headed_chrome_missing'
Assert-Contains $runner "context.route('**/*'" 'owner_existing_fixture_network_route_guard_missing'
Assert-Contains $runner 'CHROME_OWNER_LOCALHOST_ONLY_LAUNCH_ARGS' 'owner_existing_fixture_process_network_guard_missing'
Assert-Contains $runner 'isBusinessMutation' 'owner_existing_fixture_business_mutation_guard_missing'
Assert-Contains $runner "target.pathname === '/api/class-archive/comments/create'" 'owner_existing_fixture_family_denial_probe_missing'
Assert-Contains $runner 'denied?.state === 200 && denied?.status === 403' 'owner_existing_fixture_family_comment_server_denial_missing'
Assert-Contains $runner 'JSON.stringify(after) === beforeDigest' 'owner_existing_fixture_family_comment_no_write_missing'
Assert-Contains $runner 'successfulBusinessWrites === 0' 'owner_existing_fixture_zero_write_assertion_missing'
Assert-Contains $runner "home?.scope === 'FULL'" 'owner_existing_fixture_full_scope_missing'
Assert-Contains $runner "home?.scope === 'HERITAGE_ONLY'" 'owner_existing_fixture_heritage_scope_missing'
Assert-Contains $runner 'family_known_living_media_denied' 'owner_existing_fixture_living_media_denial_missing'
Assert-Contains $runner "method: 'HEAD'" 'owner_existing_fixture_head_probe_missing'
Assert-Contains $runner "Range: 'bytes=0-31'" 'owner_existing_fixture_range_probe_missing'
Assert-Contains $runner 'family_album_counts' 'owner_existing_fixture_album_count_scope_missing'
Assert-Contains $runner 'family_people_counts' 'owner_existing_fixture_people_count_scope_missing'
Assert-Contains $runner 'Object.entries({ home, pins, albumsPayload, peoplePayload, spotlight, suggestions, grouped })' 'owner_existing_fixture_search_scope_missing'
Assert-Contains $runner 'anonymous_api_identity_leak' 'owner_existing_fixture_anonymous_api_redaction_missing'
Assert-Contains $runner 'anonymous_html_identity_leak' 'owner_existing_fixture_anonymous_html_redaction_missing'
Assert-Contains $runner 'writes=0' 'owner_existing_fixture_safe_aggregate_record_missing'

foreach ($forbidden in @(
    'create_classmate', 'create_teacher', 'issue_claim', 'issue_family_invitation', 'accept_family',
    'activate_anonymous', 'freeze_identity', '/api/class-archive/member-upload', 'setInputFiles',
    'fs.copyFile', 'fs.cp', '0.0.0.0', 'https://'
)) {
    Assert-NotContains $runner $forbidden ('owner_existing_fixture_runner_forbidden_' + (($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant()))
}

Assert-Contains $provisioner "'fixture-classmate' => 'CLASSMATE'" 'owner_existing_fixture_provisioner_classmate_binding_missing'
Assert-Contains $provisioner "'fixture-family' => 'FAMILY'" 'owner_existing_fixture_provisioner_family_binding_missing'
Assert-Contains $provisioner "'fixture-teacher' => 'TEACHER'" 'owner_existing_fixture_provisioner_teacher_binding_missing'
Assert-Contains $provisioner "'fixture-anonymous' => 'ANONYMOUS'" 'owner_existing_fixture_provisioner_anonymous_binding_missing'
Assert-Contains $provisioner 'Missing bound {$username}' 'owner_existing_fixture_provisioner_must_require_existing_binding'
Assert-Contains $provisioner 'revokeAllCredentials' 'owner_existing_fixture_provisioner_session_revoke_missing'
Assert-NotContains $provisioner 'register_user' 'owner_existing_fixture_provisioner_account_creation_forbidden'

Assert-Contains $docs 'existing bound fixture principals' 'owner_existing_fixture_docs_boundary_missing'
Assert-Contains $docs '-ConfirmExistingFixtureCredentialRotation' 'owner_existing_fixture_docs_command_missing'
Assert-Contains $docs 'The harness does not create identities, seats, claims, invitations, accounts,' 'owner_existing_fixture_docs_no_creation_missing'
Assert-Contains $docs 'unknown random secret' 'owner_existing_fixture_docs_final_rotation_missing'

$previous = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $blockedOutput = @(& pwsh.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -File $wrapperPath 2>&1)
    $blockedExit = $LASTEXITCODE
}
finally { $ErrorActionPreference = $previous }
$blockedLines = @($blockedOutput | ForEach-Object { [string]$_ })
Assert-True ($blockedExit -eq 3) 'owner_existing_fixture_no_switch_exit_invalid'
Assert-True ($blockedLines.Count -eq 1 -and $blockedLines[0] -eq 'V4_OWNER_EXISTING_FIXTURE_CHROME_QA=BLOCKED code=explicit_fixture_credential_rotation_confirmation_required') 'owner_existing_fixture_no_switch_output_invalid'

Write-Output "PHOTOS_APP_V4_OWNER_EXISTING_FIXTURE_BROWSER_PROTOCOL=PASS assertions=$assertions"
