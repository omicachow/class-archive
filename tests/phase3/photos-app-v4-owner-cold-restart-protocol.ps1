[CmdletBinding()]
param()

# Static-only guard for the opt-in private Owner V4 restart verifier. It reads
# tracked source only; it does not execute Docker, WSL, HTTP, Chrome, PHP in a
# container, a private credential helper, or any ignored evidence artifact.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-cold-restart.ps1'
$snapshotPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-owner-cold-restart-snapshot.php'
$runtimeHelperPath = Join-Path $projectRoot 'infra\scripts\private-full.ps1'
$docPath = Join-Path $projectRoot 'docs\photos-app-v4-owner-cold-restart.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw ('V4_OWNER_COLD_RESTART_PROTOCOL=FAIL code=' + $Code + ' assertions=' + $script:assertions) }
    $script:assertions++
}

function Read-Tracked([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Tracked $runnerPath 'runner_missing'
$snapshot = Read-Tracked $snapshotPath 'snapshot_missing'
$runtimeHelper = Read-Tracked $runtimeHelperPath 'runtime_helper_missing'
$docs = Read-Tracked $docPath 'docs_missing'
$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'runner_parse_invalid'

# The test remains non-default, private-only, and has two distinct explicit
# acknowledgements before the owner container map is even constructed.
Assert-True ($runner.Contains('[switch]$ConfirmOwnerPrivateRestart') -and $runner.Contains('[switch]$ConfirmServingContainerRestart')) 'confirmation_surface_missing'
Assert-True ($runner.Contains("Assert-True `$ConfirmOwnerPrivateRestart.IsPresent 'owner_private_restart_confirmation_required'") -and $runner.Contains("Assert-True `$ConfirmServingContainerRestart.IsPresent 'owner_serving_restart_confirmation_required'")) 'confirmation_enforcement_missing'
Assert-True ($runner.Contains("'.codex-work\private-real-qa\reports\photos-app-v4-owner-cold-restart'")) 'ignored_evidence_root_missing'
Assert-True ($runner.Contains('check-ignore --quiet --no-index') -and $runner.Contains('ls-files --')) 'ignored_evidence_tracking_guard_missing'
Assert-True ($runner.Contains('AGGREGATES_AND_DIGESTS_ONLY') -and $runner.Contains('PRIVATE_LOCAL_IGNORED')) 'private_evidence_redaction_missing'

# Every restarted container must be both compose-label scoped and an exact
# private-full owner name. Nothing is found by a broad port scan or wildcard.
foreach ($token in @(
    "'class_archive_private_full_v3_piwigo'", "'class_archive_private_full_v3_immich'", "'private-real-full|'",
    "'class_archive_private_full_v3_piwigo-piwigo-1'", "'class_archive_private_full_v3_piwigo-db-1'",
    "'class_archive_private_full_v3_immich-database-1'", "'class_archive_private_full_v3_immich-redis-1'",
    "'class_archive_private_full_v3_immich-immich-machine-learning-1'", "'class_archive_private_full_v3_immich-immich-server-1'",
    "'class_archive_private_full_v3_immich-immich-gateway-1'", "'class_archive_private_full_v3_immich-immich-web-compat-1'",
    "'80/tcp -> 127.0.0.1:8190'", "'8081/tcp -> 127.0.0.1:8191'"
)) {
    Assert-True ($runner.Contains($token)) ('owner_scope_token_missing_' + (($token -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}
Assert-True ($runner.Contains("'label=com.docker.compose.project=' + `$Spec.project") -and $runner.Contains("'label=com.docker.compose.service=' + `$Spec.service")) 'owner_compose_label_scope_missing'
Assert-True ($runner.Contains("'private-real-full|' + `$Spec.project + '|' + `$Spec.service")) 'owner_scope_label_assertion_missing'
$privateDriveBackslash = 'M' + [char]58 + [char]92
$privateDriveSlash = 'M' + [char]58 + [char]47
$independentRecoveryRoot = 'C' + [char]58 + [char]92 + 'ClassArchive'
foreach ($forbidden in @('8090','8091','8290','8291','0.0.0.0',$privateDriveBackslash,$privateDriveSlash,$independentRecoveryRoot,'private-qa')) {
    Assert-True (-not $runner.Contains($forbidden)) ('non_owner_or_private_path_leak_' + (($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}

# `docker restart` is the only lifecycle operation. The wrapper may inspect
# and compose-exec the known owner Piwigo service to run the read-only snapshot,
# but it must never materialize, replace, pull, build, remove or down a stack.
Assert-True ($runner.Contains("Invoke-OwnerDocker @('restart',`$containers[[string]`$spec.key].id)")) 'bounded_restart_command_missing'
foreach ($forbidden in @(
    'docker compose up','docker compose down','docker compose stop','docker compose start','docker compose rm',
    'docker volume rm','docker system prune','--pull','--build','--force-recreate','--renew-anon-volumes',
    "'-v'",' Remove-Item ',' Move-Item ',' Copy-Item ','Set-Content','Add-Content','Out-File'
)) {
    Assert-True (-not $runner.Contains($forbidden)) ('forbidden_lifecycle_or_copy_surface_' + (($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}
Assert-True ($runner.Contains("'immich-gateway-secret-stager'") -eq $false) 'one_shot_secret_stager_must_not_restart'

# Existing owner boundary validation is required on both sides. This is a
# safe, established helper and proves exact loopback ownership before any
# restart evidence is emitted.
Assert-True ($runner.Contains('Invoke-OwnerRuntimeBoundary') -and $runner.Contains("Invoke-OwnerRuntimeBoundary 'before'") -and $runner.Contains("Invoke-OwnerRuntimeBoundary 'after_restart'") -and $runner.Contains("Invoke-OwnerRuntimeBoundary 'final'")) 'owner_runtime_boundary_bracket_missing'
Assert-True ($runtimeHelper.Contains("'runtime-owner' = 'owner'") -or $runtimeHelper.Contains("'runtime-owner'")) 'existing_owner_runtime_helper_contract_missing'

# The snapshot must be a local, read-only aggregate and is compared before,
# immediately after, and after temporary session cleanup. Open index jobs are
# a fail-closed result, not a condition the runner attempts to repair.
Assert-True ($runner.Contains('Get-OwnerSnapshot') -and $runner.Contains('Assert-OwnerSnapshotSame $before $afterRestart') -and $runner.Contains('Assert-OwnerSnapshotSame $before $final')) 'snapshot_comparison_missing'
Assert-True ($runner.Contains("'owner_snapshot_' + `$Stage + '_ai_reindex_jobs_open'")) 'open_ai_job_fail_closed_missing'
Assert-True ($runner.Contains('New-ClassArchiveSystemAdminSession') -and $runner.Contains('Remove-ClassArchiveSystemAdminSession')) 'temporary_admin_session_cleanup_missing'
foreach ($path in @('collections/home','collections/pins','class-archive/timeline','api/people','search/suggestions','search/grouped')) {
    Assert-True ($runner.Contains($path)) ('immediate_read_endpoint_missing_' + (($path -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}
Assert-True ($runner.Contains("'V4_OWNER_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO scope=OWNER_8190_8191 evidence=PRIVATE_LOCAL_IGNORED'")) 'terminal_pass_record_missing'

# Snapshot source has no data-changing SQL/filesystem code and cannot be run
# against generic or public Piwigo. It returns counts/digests only, with a
# session-local GROUP_CONCAT bound sufficient for deterministic aggregate data.
foreach ($token in @('CLASS_ARCHIVE_V4_OWNER_COLD_RESTART_SNAPSHOT','CLASS_ARCHIVE_RUNTIME_SCOPE','PRIVATE_REAL_FULL','CLASS_ARCHIVE_PRIVATE_REAL_FULL','posix_geteuid','nginx','SET SESSION `group_concat_max_len`','photo_comment','ai_asset_index','ai_index_job','collection_snapshot_pointer','spotlight_rotation_state','JSON_THROW_ON_ERROR')) {
    Assert-True ($snapshot.Contains($token)) ('snapshot_contract_token_missing_' + (($token -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}
foreach ($forbidden in @('INSERT ','UPDATE ','DELETE ','ALTER ','CREATE ','DROP ','TRUNCATE ','unlink(','file_put_contents(','fopen(','scandir(','RecursiveDirectoryIterator','/private-real-full/staging','source_filename','absolute_path')) {
    Assert-True (-not $snapshot.Contains($forbidden)) ('snapshot_write_or_private_surface_' + (($forbidden -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}
Assert-True ($snapshot.Contains('COUNT(*)') -and $snapshot.Contains('SHA2(') -and $snapshot.Contains('AGGREGATES_AND_DIGESTS_ONLY') -eq $false) 'snapshot_aggregate_digest_contract_missing'

# Operator documentation must retain the no-credentials/no-public-evidence
# boundary and spell out that this is a restart drill, not a backup or index
# rebuild workflow.
foreach ($token in @('Google Chrome','8190','8191','two explicit confirmations','does not','AI','MediaGuard','ignored','not a backup','no reindex')) {
    Assert-True ($docs.Contains($token)) ('docs_contract_token_missing_' + (($token -replace '[^A-Za-z0-9]+','_').Trim('_').ToLowerInvariant()))
}

Write-Output ('V4_OWNER_COLD_RESTART_PROTOCOL=PASS assertions=' + $assertions)
