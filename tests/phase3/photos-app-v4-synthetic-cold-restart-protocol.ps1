[CmdletBinding()]
param()

# Static-only protocol for the public V4 cold-restart runner. It parses source
# only: no Docker, Chrome, WSL runtime command, credential file, or HTTP call
# is executed here.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$projectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$runnerPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-synthetic-cold-restart.ps1'
$snapshotPath = Join-Path $projectRoot 'tests\phase3\photos-app-v4-synthetic-cold-restart-snapshot.php'
$projectionPath = Join-Path $projectRoot 'tests\phase3\read-projection-runtime.ps1'
$docsPath = Join-Path $projectRoot 'docs\photos-app-v4-synthetic-cold-restart.md'
$assertions = 0

function Assert-True([bool]$Condition, [string]$Code) {
    if (-not $Condition) { throw $Code }
    $script:assertions++
}

function Read-Source([string]$Path, [string]$Code) {
    Assert-True (Test-Path -LiteralPath $Path -PathType Leaf) $Code
    return [IO.File]::ReadAllText($Path)
}

$runner = Read-Source $runnerPath 'v4_cold_restart_runner_missing'
$snapshot = Read-Source $snapshotPath 'v4_cold_restart_snapshot_missing'
$projection = Read-Source $projectionPath 'v4_cold_restart_projection_runtime_missing'
$docs = Read-Source $docsPath 'v4_cold_restart_docs_missing'
$tokens = $null
$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($runnerPath, [ref]$tokens, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'v4_cold_restart_runner_parse_invalid'

# The production runner is deliberately opt-in because it performs a bounded
# synthetic archive mutation through the already-established runtime proof
# before its clean, V4-specific restart.
Assert-True ($runner.Contains('[switch]$ConfirmSyntheticMutation') -and $runner.Contains('[switch]$ConfirmServiceRestart')) 'v4_cold_restart_confirmation_surface_missing'
Assert-True ($runner.Contains('Invoke-ExistingProjectionRuntime') -and $runner.Contains('read-projection-runtime.ps1') -and $runner.Contains('-ConfirmSyntheticMutation -ConfirmServiceRestart')) 'v4_cold_restart_existing_projection_proof_missing'
Assert-True ($projection.Contains('[int]$snapshot.schema_version -eq 18') -and $projection.Contains('schema_version = 18')) 'v4_cold_restart_legacy_schema_version_stale'

# Public container discovery is label-bound and all listener assertions are
# loopback-only. The runner may restart existing public services but must not
# start/stop a compose project or mention a private Owner endpoint.
foreach ($token in @("'class_archive_piwigo'", "'class-archive-immich-spike'", "'piwigo'", "'immich-web-compat'", "'80/tcp -> 127.0.0.1:8090'", "'8081/tcp -> 127.0.0.1:8091'", "Invoke-WslDocker @('restart', `$compat.id, `$piwigo.id)")) {
    Assert-True ($runner.Contains($token)) ('v4_cold_restart_public_container_contract_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
foreach ($forbidden in @('docker compose up', 'docker compose down', 'docker compose stop', '8191', '8190', '0.0.0.0', 'private-real', 'runtime-owner')) {
    Assert-True (-not $runner.Contains($forbidden)) ('v4_cold_restart_private_or_lifecycle_escape_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
Assert-True ($runner.Contains('private[_-]qa') -and $runner.Contains('v4_synthetic_cold_restart_private_mount_detected')) 'v4_cold_restart_private_mount_guard_missing'

# A successful record requires baseline checks on both sides of a genuine
# restart and a byte-for-byte stable aggregate snapshot. The V4 endpoints are
# read only through an exact short-lived fixture lease, which is revoked in a
# finally block before an attester-eligible marker can print.
Assert-True ($runner.Contains('Assert-SyntheticBaseline') -and $runner.Contains("'v4_synthetic_cold_restart_baseline_before_failed'") -and $runner.Contains("'v4_synthetic_cold_restart_baseline_after_failed'")) 'v4_cold_restart_baseline_bracket_missing'
Assert-True ($runner.Contains('Get-V4ColdRestartSnapshot') -and $runner.Contains('Assert-V4ColdRestartSnapshot') -and $runner.Contains('Assert-V4ColdRestartStable')) 'v4_cold_restart_snapshot_comparison_missing'
Assert-True ($runner.Contains('New-ClassArchiveSystemAdminSession') -and $runner.Contains('Remove-ClassArchiveSystemAdminSession') -and $runner.Contains('collections/home') -and $runner.Contains('collections/pins') -and $runner.Contains('search/suggestions') -and $runner.Contains('class-archive/timeline')) 'v4_cold_restart_immediate_projection_read_missing'
Assert-True ($runner.Contains("'V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8'") -and $runner.Contains("'V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS'")) 'v4_cold_restart_attester_records_missing'
$finallyIndex = $runner.LastIndexOf('finally {', [StringComparison]::Ordinal)
$passIndex = $runner.IndexOf("V4_SYNTHETIC_COLD_RESTART=PASS projections=IMMEDIATE ai_reindex=NO baseline=72_72_8", [StringComparison]::Ordinal)
$completeIndex = $runner.IndexOf('V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS', [StringComparison]::Ordinal)
Assert-True ($finallyIndex -ge 0 -and $passIndex -gt $finallyIndex -and $completeIndex -gt $passIndex) 'v4_cold_restart_terminal_marker_order_invalid'

# The CLI helper is read-only and emits only aggregate counters/digests. It
# binds to the current V18 schema plus all durable V4 components that must be
# present before a restart can be called immediate/no-reindex evidence.
foreach ($token in @("getenv('CLASS_ARCHIVE_V4_SYNTHETIC_COLD_RESTART') !== '1'", 'posix_geteuid() === 0', "const V4_COLD_RESTART_ROOT = '/var/www/html/piwigo'", 'Schema::CURRENT_VERSION !== 18', 'migration_version_not_v18', 'synthetic_baseline_drift', 'collection_snapshot_pointer', 'collection_maintenance_state', 'spotlight_rotation_state', 'ai_asset_index', 'ai_index_job', 'v4ColdRestartDigest', "'open_job_count' => `$openJobs")) {
    Assert-True ($snapshot.Contains($token)) ('v4_cold_restart_snapshot_contract_missing_' + ($token -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}
$privateSourceDrivePrefix = ('M' + [char]58 + [char]92)
foreach ($forbidden in @('INSERT ', 'UPDATE ', 'DELETE ', 'unlink(', 'delete_elements(', '8191', '8190', '0.0.0.0', $privateSourceDrivePrefix)) {
    Assert-True (-not $snapshot.Contains($forbidden)) ('v4_cold_restart_snapshot_mutation_or_private_escape_' + ($forbidden -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

foreach ($term in @('STATIC', 'RUNTIME_TESTED', '72 / 72 / 8', 'never runs `docker compose up` or `down`', 'V4_SYNTHETIC_COLD_RESTART_COMPLETE=PASS', 'finally')) {
    Assert-True ($docs.Contains($term)) ('v4_cold_restart_docs_boundary_missing_' + ($term -replace '[^A-Za-z0-9]+', '_').Trim('_').ToLowerInvariant())
}

Write-Output "PHOTOS_APP_V4_SYNTHETIC_COLD_RESTART_PROTOCOL=PASS assertions=$assertions"
