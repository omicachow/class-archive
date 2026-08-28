[CmdletBinding()]
param()

# Static guard for the separately controlled, mutating synthetic upload runner.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$files = @{
    runner = Join-Path $PSScriptRoot 'photos-app-v4-chrome-upload-lifecycle.mjs'
    wrapper = Join-Path $PSScriptRoot 'photos-app-v4-chrome-upload-lifecycle.ps1'
    fixture = Join-Path $PSScriptRoot 'photos-app-v4-upload-lifecycle-fixture.php'
}
foreach ($path in $files.Values) { if (-not (Test-Path -LiteralPath $path -PathType Leaf)) { throw 'v4_upload_protocol_file_missing' } }
$runner = [IO.File]::ReadAllText($files.runner)
$wrapper = [IO.File]::ReadAllText($files.wrapper)
$fixture = [IO.File]::ReadAllText($files.fixture)
$tokens = $null; $parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($files.wrapper, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count -ne 0) { throw 'v4_upload_protocol_wrapper_parse_invalid' }
$node = Join-Path ([Environment]::GetFolderPath([Environment+SpecialFolder]::UserProfile)) '.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
if (-not (Test-Path -LiteralPath $node -PathType Leaf)) { throw 'v4_upload_protocol_node_parse_unavailable' }
& $node --check $files.runner
if ($LASTEXITCODE -ne 0) { throw 'v4_upload_protocol_runner_parse_invalid' }
function Assert-Contains([string]$Source,[string]$Needle,[string]$Code) { if (-not $Source.Contains($Needle)) { throw $Code } }
Assert-Contains $runner "channel: 'chrome'" 'v4_upload_protocol_chrome_channel'
Assert-Contains $runner 'headless: false' 'v4_upload_protocol_headed'
Assert-Contains $runner "waitForEvent('filechooser'" 'v4_upload_protocol_filechooser'
Assert-Contains $runner 'chooser.setFiles' 'v4_upload_protocol_real_file_selection'
Assert-Contains $runner "SYNTHETIC_UPLOAD_ROOT = path.join(PROJECT_ROOT, '.codex-work', 'runtime', 'phase3-upload-lifecycle')" 'v4_upload_protocol_fixture_root_boundary'
Assert-Contains $runner "fixture_root_scope" 'v4_upload_protocol_fixture_run_scope'
Assert-Contains $runner "fixture_png_marker" 'v4_upload_protocol_fixture_marker'
Assert-Contains $runner '[a-z0-9-]{3,112}\.png' 'v4_upload_protocol_fixture_png_leaf_path'
Assert-Contains $runner "PUBLISHED_INTENT" 'v4_upload_protocol_published_cleanup_intent'
Assert-Contains $runner 'FAMILY_TAMPER_FIXTURE' 'v4_upload_protocol_family_tamper_fixture'
Assert-Contains $runner "node.value = 'LIVING'" 'v4_upload_protocol_family_living_tamper'
Assert-Contains $runner 'family_tamper_validation_error' 'v4_upload_protocol_family_tamper_error'
Assert-Contains $runner 'photoId: payload.photoId.toLowerCase()' 'v4_upload_protocol_response_uuid'
Assert-Contains $runner 'checksum: record.checksum' 'v4_upload_protocol_response_checksum'
Assert-Contains $runner 'writeJournal(settings.journal, journal)' 'v4_upload_protocol_journal'
Assert-Contains $wrapper 'finally {' 'v4_upload_protocol_finally'
Assert-Contains $wrapper "'assert-absent'" 'v4_upload_protocol_tamper_absent'
Assert-Contains $wrapper "'cleanup-published'" 'v4_upload_protocol_published_cleanup'
Assert-Contains $wrapper "'cleanup-published-by-checksum'" 'v4_upload_protocol_interrupted_published_cleanup'
Assert-Contains $wrapper "'cleanup-pending'" 'v4_upload_protocol_pending_cleanup'
Assert-Contains $wrapper 'images=72 originals=72 multi_album=8' 'v4_upload_protocol_baseline_output'
Assert-Contains $wrapper 'V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS' 'v4_upload_protocol_post_finally_completion'
Assert-Contains $wrapper 'v4_upload_result_missing' 'v4_upload_protocol_result_required'
Assert-Contains $wrapper 'Invoke-SyntheticMutationLock' 'v4_upload_protocol_shared_mutation_lock'
Assert-Contains $wrapper "'lock' -Run `$run" 'v4_upload_protocol_shared_mutation_lock_acquire'
Assert-Contains $wrapper "'unlock' -Run `$run" 'v4_upload_protocol_shared_mutation_lock_release'
Assert-Contains $wrapper 'v4_upload_run_output_cleanup_failed' 'v4_upload_protocol_profile_cleanup_verify'
if ($wrapper -match 'Get-ChildItem.+(?:upload|fixture)' -or $wrapper -match 'Remove-Item.+\*') { throw 'v4_upload_protocol_filename_or_glob_cleanup' }
Assert-Contains $fixture "getenv('CLASS_ARCHIVE_V4_UPLOAD_LIFECYCLE') !== '1'" 'v4_upload_protocol_fixture_gate'
Assert-Contains $fixture "ciulCleanupPublished(string `$uuid, string `$checksum)" 'v4_upload_protocol_uuid_checksum_cleanup'
Assert-Contains $fixture "hash_file('sha256'" 'v4_upload_protocol_checksum_recheck'
Assert-Contains $fixture "ciulCleanupPublishedByChecksum" 'v4_upload_protocol_interrupted_checksum_cleanup'
Assert-Contains $fixture "ciulAssertPendingReferencesExclusive" 'v4_upload_protocol_pending_reference_exclusive'
Assert-Contains $fixture 'WHERE `id`=' 'v4_upload_protocol_core_id_unique'
Assert-Contains $fixture 'ClassArchivePhoto::normalizeMediaReference' 'v4_upload_protocol_core_path_normalized'
Assert-Contains $fixture 'delete_elements([$imageId], true)' 'v4_upload_protocol_core_delete'
Assert-Contains $fixture 'published_external_ai_asset_present' 'v4_upload_protocol_external_ai_fail_closed'
Assert-Contains $fixture 'synthetic_baseline_drift' 'v4_upload_protocol_baseline'
if ($wrapper.LastIndexOf("Write-Output 'V4_CHROME_UPLOAD_LIFECYCLE_COMPLETE=PASS'", [StringComparison]::Ordinal) -le $wrapper.LastIndexOf('finally {', [StringComparison]::Ordinal)) { throw 'v4_upload_protocol_completion_must_follow_finally' }
Write-Output 'V4_CHROME_UPLOAD_LIFECYCLE_PROTOCOL=PASS'
