[CmdletBinding()]
param()

# Static-only contract for the attempt27 orchestration layer. It opens
# tracked source text only; no WSL, Docker, database, browser, media volume or
# private Owner state is contacted.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'infra\scripts\v16-to-v18-synthetic-direct-runtime.ps1'
$proofPath = Join-Path $projectRoot 'infra\scripts\v16-to-v18-synthetic-direct-proof.php'
$baseRunnerPath = Join-Path $projectRoot 'infra\scripts\v18-synthetic-migration.ps1'
$restorePath = Join-Path $projectRoot 'infra\scripts\restore-v4-synthetic-pre-migration-db.sh'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Slice-Function([string]$Text, [string]$StartName, [string]$NextName, [string]$Code) {
    $start = $Text.IndexOf($StartName, [StringComparison]::Ordinal)
    $next = $Text.IndexOf($NextName, [StringComparison]::Ordinal)
    Assert-True ($start -ge 0 -and $next -gt $start) $Code
    return $Text.Substring($start, $next - $start)
}

Assert-True (Test-Path -LiteralPath $runnerPath -PathType Leaf) 'direct_runtime_runner_missing'
Assert-True (Test-Path -LiteralPath $proofPath -PathType Leaf) 'direct_runtime_proof_missing'
Assert-True (Test-Path -LiteralPath $baseRunnerPath -PathType Leaf) 'direct_runtime_base_runner_missing'
Assert-True (Test-Path -LiteralPath $restorePath -PathType Leaf) 'direct_runtime_restore_helper_missing'

$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'direct_runtime_runner_parse_error'

$runner = [IO.File]::ReadAllText($runnerPath)
$restore = [IO.File]::ReadAllText($restorePath)
$enginePipeFunction = Slice-Function $runner 'function Assert-DockerDesktopEnginePipe' 'function Invoke-BaseRunner' 'direct_runtime_engine_pipe_function_boundary_missing'
$baseRunnerFunction = Slice-Function $runner 'function Invoke-BaseRunner' 'function Get-SandboxValues' 'direct_runtime_base_runner_function_boundary_missing'
$directComposeFunction = Slice-Function $runner 'function Invoke-DirectCompose' 'function Get-DirectSchemaVersion' 'direct_runtime_direct_compose_function_boundary_missing'

# There is exactly one allowable laboratory identity.  The orchestration
# surface has no user-selectable attempt, port, project, owner, or source path.
Assert-True ($runner.Contains("`$attempt = 'attempt27'") -and $runner.Contains("`$httpPort = '11190'") -and $runner.Contains("`$compatPort = '11191'") -and $runner.Contains("`$composeProject = 'class_archive_v18_synthetic_migration_attempt27'")) 'direct_runtime_attempt27_identity_not_fixed'
Assert-True ($runner.Contains("[ValidateSet('status', 'initialize', 'restore', 'restore-and-prove', 'prove', 'verify')]") -and -not $runner.Contains('[string]$Attempt')) 'direct_runtime_action_surface_not_bounded'
$privateSourceMarker = (([string][char]77) + ':' + [char]92) + '图片资源'
$recoveryTargetMarker = (([string][char]67) + ':' + [char]92) + 'ClassArchive'
foreach ($forbiddenTarget in @('8091','8191','8291','private-real','runtime-owner',$privateSourceMarker,$recoveryTargetMarker,'sailor-ingest')) {
    Assert-True (-not $runner.Contains($forbiddenTarget)) ('direct_runtime_non_synthetic_target_forbidden_' + ($forbiddenTarget -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The direct laboratory must not start a WSL/Docker client if Docker Desktop's
# Windows engine pipe has disappeared. Both state-changing paths delegate to
# this fail-closed guard before path conversion or native process startup.
Assert-True ($enginePipeFunction.Contains('dockerDesktopLinuxEngine') -and $enginePipeFunction.Contains('docker_engine') -and $enginePipeFunction.Contains('Test-Path -LiteralPath $_') -and $enginePipeFunction.Contains("Stop-V16ToV18DirectRuntime 'docker_engine_pipe_unavailable'")) 'direct_runtime_engine_pipe_fail_closed_contract_missing'
Assert-True (-not $enginePipeFunction.Contains('Invoke-NativeCapture') -and -not $enginePipeFunction.Contains('Get-WslPath') -and -not $enginePipeFunction.Contains('docker compose')) 'direct_runtime_engine_pipe_guard_must_not_probe_runtime'
$basePipeIndex = $baseRunnerFunction.IndexOf('Assert-DockerDesktopEnginePipe', [StringComparison]::Ordinal)
$baseCaptureIndex = $baseRunnerFunction.IndexOf('New-BaseRunnerCapturePaths', [StringComparison]::Ordinal)
$baseStartIndex = $baseRunnerFunction.IndexOf('Start-Process', [StringComparison]::Ordinal)
$composePipeIndex = $directComposeFunction.IndexOf('Assert-DockerDesktopEnginePipe', [StringComparison]::Ordinal)
$composeWslPathIndex = $directComposeFunction.IndexOf('Get-WslPath', [StringComparison]::Ordinal)
Assert-True ($basePipeIndex -ge 0 -and $baseCaptureIndex -gt $basePipeIndex -and $baseStartIndex -gt $baseCaptureIndex) 'direct_runtime_engine_pipe_must_precede_base_runner'
Assert-True ($composePipeIndex -ge 0 -and $composeWslPathIndex -gt $composePipeIndex) 'direct_runtime_engine_pipe_must_precede_direct_compose'
$wslPathFunction = Slice-Function $runner 'function Get-WslPath' 'function Assert-DockerDesktopEnginePipe' 'direct_runtime_utf8_wsl_path_function_boundary_missing'
Assert-True ($wslPathFunction.Contains('StandardOutputEncoding = [Text.UTF8Encoding]::new($false)') -and $wslPathFunction.Contains('StandardErrorEncoding = [Text.UTF8Encoding]::new($false)') -and $wslPathFunction.Contains('wsl_path_argument_invalid') -and -not $wslPathFunction.Contains('Invoke-NativeCapture $wsl')) 'direct_runtime_utf8_wsl_path_contract_missing'

# Initialisation/restore are delegated to the existing guarded V16 DB-only
# lab runner.  The direct orchestrator cannot invoke historical bootstrap or
# its V17->V18 migration action.
Assert-True ($runner.Contains('function Invoke-BaseRunner') -and $runner.Contains("@('initialize','restore')") -and $runner.Contains("Invoke-BaseRunner 'initialize'") -and $runner.Contains("Invoke-BaseRunner 'restore' -RestoreConfirmation")) 'direct_runtime_base_restore_reuse_missing'
Assert-True ($runner.Contains('function New-BaseRunnerCapturePaths') -and $runner.Contains('base-runner-capture') -and $runner.Contains('Start-Process -FilePath $windowsPowerShell') -and $runner.Contains('-RedirectStandardOutput $capture.Stdout') -and $runner.Contains('-RedirectStandardError $capture.Stderr') -and $runner.Contains('base_capture_too_large') -and $runner.Contains("'base_runner_' + `$BaseAction + '_timeout'")) 'direct_runtime_owner_only_base_capture_missing'
Assert-True ($runner.Contains('if (-not $process.HasExited)') -and $runner.Contains("Stop-V16ToV18DirectRuntime 'base_runner_exit_state_invalid'") -and $runner.Contains('$exitCode = [int]$process.ExitCode')) 'direct_runtime_child_exit_state_validation_missing'
Assert-True ($runner.Contains('function Invoke-RestoreAndProve') -and $runner.Contains('synthetic_restore_and_migration_confirmation_required') -and $runner.Contains('Invoke-Restore') -and $runner.Contains('Invoke-Prove') -and $runner.Contains("'restore-and-prove' { Invoke-RestoreAndProve }")) 'direct_runtime_bounded_restore_and_prove_missing'
Assert-True ($runner.Contains('create-pre-migration-db-snapshot.sh') -and $runner.Contains('restore-v4-synthetic-pre-migration-db.sh')) 'direct_runtime_snapshot_producer_in_source_closure_missing'
Assert-True ($restore.Contains("expected_current_snapshot_script_sha='1897ea83db59c9126125ce63afe538e7a73e58ee1386db5acf518b6ddafaf7c5'") -and $restore.Contains('9c5035e26aec9b3f616272f48d4a0c5a3ce81b0a505ac7bc71ad5a47176db7c0') -and $restore.Contains('snapshot_restore_mechanism_unreviewed') -and $restore.Contains('snapshot_not_created_by_reviewed_mechanism')) 'direct_runtime_restore_producer_allowlist_missing'
Assert-True ($restore.Contains('case "$manifest_script_sha" in') -and -not $restore.Contains('snapshot_not_created_by_current_mechanism')) 'direct_runtime_restore_dynamic_producer_equality_forbidden'
foreach ($forbiddenBaseInvocation in @("Invoke-BaseRunner\s+'bootstrap-v17'", "Invoke-BaseRunner\s+'migrate'", "Invoke-BaseRunner\s+'recover'", 'Invoke-BootstrapV17', 'Invoke-MigrateV18')) {
    Assert-True ($runner -notmatch $forbiddenBaseInvocation) ('direct_runtime_historical_base_action_forbidden_' + ($forbiddenBaseInvocation -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains('source=V16_DB_ONLY') -and $runner.Contains('media=NOT_MOUNTED') -and $runner.Contains('direct_restore_not_v16')) 'direct_runtime_v16_restore_boundary_missing'

# The current proof must run as nginx with both runtime gates.  The result is
# strict: first migration, replay, read-only verification, and unknown-ledger
# fail closed each have a fixed evidence contract.
Assert-True ($runner.Contains("'--user','nginx'") -and $runner.Contains("'CLASS_ARCHIVE_V16_TO_V18_DIRECT_PROOF=1'") -and $runner.Contains("'CLASS_ARCHIVE_RUNTIME_SCOPE=SYNTHETIC_V4_MIGRATION'") -and $runner.Contains('/workspace/infra/scripts/v16-to-v18-synthetic-direct-proof.php')) 'direct_runtime_nginx_scope_gate_missing'
Assert-True ($runner.Contains("Invoke-DirectProof '--migrate-current-source'") -and $runner.Contains("Invoke-DirectProof '--verify-current-source'") -and $runner.Contains("Invoke-DirectProof '--fail-closed'")) 'direct_runtime_current_source_proof_modes_missing'
Assert-True ($runner.Contains('schema_from=16 schema_to=18 sequential=17_18 replay=NOT_APPLICABLE') -and $runner.Contains('legacy_tables_preserved=PASS') -and $runner.Contains('new_table_count=7')) 'direct_runtime_first_migration_evidence_missing'
Assert-True ($runner.Contains('schema_from=18 schema_to=18 sequential=NOT_APPLICABLE replay=PASS') -and $runner.Contains('stage=verify_current_source schema=18 ledger=18') -and $runner.Contains('unknown_schema=DENY scratch=DISPOSED')) 'direct_runtime_replay_verify_fail_closed_evidence_missing'
Assert-True ($runner.Contains('direct_proof_requires_fresh_v16_lab') -and $runner.Contains('direct_proof_report_exists_preserved_lab') -and $runner.Contains('No cleanup action exists')) 'direct_runtime_failed_lab_preservation_missing'

# A proof report must be evidence for the exact reviewed source closure which
# actually ran.  Recording only a post-hoc attestation would let a later
# source revision bless an older runtime result.  The runner therefore pins a
# clean tracked closure before the proof, rechecks it after the proof, and
# persists both commit and digest in the ignored report.
Assert-True ($runner.Contains('function Get-ProofSourceClosure') -and $runner.Contains('function Assert-ProofSourceClosure') -and $runner.Contains('proof_source_worktree_not_head_bound') -and $runner.Contains('proof_source_index_not_head_bound')) 'direct_runtime_proof_source_clean_closure_missing'
foreach ($proofClosurePath in @('infra/docker-compose.yml', 'infra/v18-synthetic-migration/docker-compose.override.yml', 'infra/scripts/v18-synthetic-db-probe.sh', 'infra/scripts/v18-synthetic-migration.ps1', 'infra/scripts/restore-v4-synthetic-pre-migration-db.sh', 'infra/scripts/v16-to-v18-synthetic-direct-proof.php', 'infra/scripts/v16-to-v18-synthetic-direct-runtime.ps1', 'plugins/ClassIdentity/src/Schema.php')) {
    Assert-True ($runner.Contains("'$proofClosurePath'")) ('direct_runtime_proof_source_closure_path_missing_' + ($proofClosurePath -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains("'infra/scripts/attest-v16-to-v18-synthetic-direct-runtime.ps1'") -and $runner.Contains('function ConvertTo-NormalizedSourceEntries') -and $runner.Contains("ConvertTo-NormalizedSourceEntries @(`$records) 'proof_source_entry_invalid'") -and $runner.Contains('Select-Object -Unique')) 'direct_runtime_source_entry_canonicalization_missing'
Assert-True ($runner.Contains('$sourceClosure = Get-ProofSourceClosure') -and $runner.Contains("Assert-ProofSourceClosure `$sourceClosure 'direct_proof_source_changed_during_run'") -and $runner.Contains("Assert-ProofSourceClosure `$sourceClosure 'direct_verify_source_changed_during_run'")) 'direct_runtime_proof_source_closure_before_after_missing'

# Runtime output must be non-secret by construction.  The ignored report is
# bound to the fixed lab and contains only evidence strings and an opaque
# schema fingerprint; native stderr is never forwarded into logs.
Assert-True ($runner.Contains('v16-to-v18-direct-proof.json') -and $runner.Contains('Assert-IgnoredUntracked $reportPath') -and $runner.Contains('format = 2') -and $runner.Contains("source_commit = `$SourceClosure.Commit") -and $runner.Contains("source_digest = `$SourceClosure.SourceDigest") -and $runner.Contains("legacy_fingerprint = `$Fingerprint") -and $runner.Contains("media = 'NOT_MOUNTED'")) 'direct_runtime_ignored_evidence_report_missing'
Assert-True ($runner.Contains('$record.format -ne 2') -and $runner.Contains('direct_proof_source_closure_stale') -and $runner.Contains('source_commit) -notmatch') -and $runner.Contains('source_digest) -notmatch')) 'direct_runtime_proof_report_source_binding_missing'
Assert-True ($runner.Contains('Deliberately never echo stderr') -and -not $runner.Contains('Write-Output $stderr') -and -not $runner.Contains('Write-Error $stderr')) 'direct_runtime_stderr_secret_boundary_missing'
Assert-True ($runner.Contains("'V18_SYNTHETIC_MIGRATION_STOP:'") -and $runner.Contains('child_failure_prefix_invalid') -and $runner.Contains('[regex]::Match($stderr') -and $runner.Contains("'([a-z0-9_]{1,96})'")) 'direct_runtime_bounded_child_failure_diagnostic_missing'
Assert-True ($runner.Contains('[int]$TimeoutSeconds = 240') -and $runner.Contains("Stop-V16ToV18DirectRuntime 'native_timeout_invalid'") -and $runner.Contains("Stop-V16ToV18DirectRuntime (`$FailureCode + '_timeout')")) 'direct_runtime_bounded_child_timeout_missing'

# Every fail-closed path is a single, bounded, path-free record.  In
# particular, PowerShell's Write-Error formatting would add a script path and
# invocation context.  The runner may inspect an exception only to select a
# safe STOP code; it must not echo that exception (or any native stderr) back
# to the caller.
Assert-True ($runner.Contains("`$message -match '^V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME_STOP:([a-z0-9_]{1,96})`$'")) 'direct_runtime_stop_code_parser_not_bounded'
Assert-True ($runner.Contains("`$type -notmatch '^[A-Za-z0-9]{1,64}`$'") -and $runner.Contains("'unexpected_' + `$type.ToLowerInvariant()")) 'direct_runtime_unexpected_code_parser_not_bounded'
Assert-True ($runner.Contains("Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME=FAIL stage=' + `$script:stage + ' code=' + `$code)") -and $runner.Contains('    exit 1')) 'direct_runtime_single_line_fail_output_missing'
Assert-True (@($tokens | Where-Object { $_.Kind -eq 'Generic' -and $_.Text -ieq 'Write-Error' }).Count -eq 0) 'direct_runtime_write_error_command_forbidden'
foreach ($forbiddenFailureOutput in @('Write-Host', 'Write-Warning', 'Write-Verbose', 'Write-Debug', 'Write-Information', 'Write-Output $message', 'Write-Output $_', 'Exception.ToString()', 'InvocationInfo', 'ScriptStackTrace')) {
    Assert-True (-not $runner.Contains($forbiddenFailureOutput)) ('direct_runtime_path_rich_failure_output_forbidden_' + ($forbiddenFailureOutput -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$runtimeCatchIndex = $runner.LastIndexOf('} catch {', [StringComparison]::Ordinal)
Assert-True ($runtimeCatchIndex -ge 0) 'direct_runtime_final_catch_missing'
$runtimeFinalCatch = $runner.Substring($runtimeCatchIndex)
Assert-True ($runtimeFinalCatch.Contains('$message = [string]$_.Exception.Message') -and $runtimeFinalCatch.Contains("`$message -match '^V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME_STOP:([a-z0-9_]{1,96})`$'") -and $runtimeFinalCatch.Contains("Write-Output ('V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME=FAIL stage=' + `$script:stage + ' code=' + `$code)") -and $runtimeFinalCatch.Contains('exit 1')) 'direct_runtime_final_catch_path_free_contract_missing'
Assert-True (([regex]::Matches($runtimeFinalCatch, '(?m)^\s*Write-Output\s+\(')).Count -eq 1) 'direct_runtime_final_catch_must_emit_exactly_one_line'
foreach ($forbiddenDestructive in @('docker compose down','docker volume rm','docker rm ','Remove-Item','Move-Item','Copy-Item','Clear-Content','Set-Content')) {
    Assert-True (-not $runner.Contains($forbiddenDestructive)) ('direct_runtime_destructive_operation_forbidden_' + ($forbiddenDestructive -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

# The direct proof remains independent from a historical source bridge; that
# assertion is duplicated here so the orchestration test cannot hide a later
# regression in the PHP helper.
$proof = [IO.File]::ReadAllText($proofPath)
foreach ($requiredProof in @('Schema::CURRENT_VERSION !== 18','--migrate-current-source','--verify-current-source','--fail-closed','schema_from=16 schema_to=18 sequential=17_18','media=NOT_TOUCHED')) {
    Assert-True ($proof.Contains($requiredProof)) ('direct_runtime_proof_contract_missing_' + ($requiredProof -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($forbiddenProof in @('V18_SYNTHETIC_V17_SCHEMA','LoadHistoricalSchema','bootstrap-v17','historical_commit')) {
    Assert-True (-not $proof.Contains($forbiddenProof)) ('direct_runtime_proof_historical_bridge_forbidden_' + ($forbiddenProof -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "V16_TO_V18_SYNTHETIC_DIRECT_RUNTIME_PROTOCOL=PASS assertions=$assertions"
