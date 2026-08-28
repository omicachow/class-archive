[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$assertions = 0

function Assert-Protocol([bool]$Condition, [string]$Code) {
    $script:assertions++
    if (-not $Condition) { throw "PRIVATE_FULL_AI_PROTOCOL=FAIL code=$Code assertions=$script:assertions" }
}

$operator = Get-Content -LiteralPath (Join-Path $root 'infra\scripts\private-full-immich.ps1') -Raw
$runner = Get-Content -LiteralPath (Join-Path $root 'infra\scripts\private-qa-immich.ps1') -Raw
$runtime = Get-Content -LiteralPath (Join-Path $root 'infra\scripts\private-qa-immich-runtime.mjs') -Raw
$bridge = Get-Content -LiteralPath (Join-Path $root 'infra\immich-spike\bridge\server.mjs') -Raw
$bridgeAdapter = Get-Content -LiteralPath (Join-Path $root 'plugins\ClassIdentity\src\Gateway\BridgeImmichAdapter.php') -Raw
$catalog = Get-Content -LiteralPath (Join-Path $root 'infra\scripts\private-qa-immich-catalog.php') -Raw
$workerOverride = Get-Content -LiteralPath (Join-Path $root 'infra\private-full\docker-compose.ai-worker.override.yml') -Raw

Assert-Protocol ($operator.Contains("worker=DISABLED_PENDING_RUNTIME_EVIDENCE")) 'cold_start_must_not_claim_worker'
Assert-Protocol ($operator.Contains("Invoke-PiwigoBaseCompose @('up', '-d', '--force-recreate', 'piwigo')")) 'cold_start_must_use_base_compose'
Assert-Protocol (-not $operator.Contains("Invoke-PiwigoWorkerCompose @('up', '-d', '--force-recreate', 'piwigo')")) 'cold_start_worker_enable_forbidden'
Assert-Protocol ($workerOverride.Contains('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER: "1"')) 'explicit_worker_override_missing'

$disableOcr = $runtime.IndexOf('await disableOutOfScopeOcr(accessToken);', [StringComparison]::Ordinal)
$libraryCreate = $runtime.IndexOf("request('library_create'", [StringComparison]::Ordinal)
Assert-Protocol ($disableOcr -gt 0 -and $libraryCreate -gt $disableOcr) 'ocr_must_be_disabled_before_scan'
foreach ($needle in @(
    "config.machineLearning.ocr.enabled = false",
    "'/jobs/ocr', 'PUT', { command: 'resume' }",
    "'/jobs/ocr', 'PUT', { command: 'clear-failed' }",
    'stats.paused === 0',
    "faceQueue = await waitForQueue(accessToken, 'faceDetection', 300_000)",
    "recognitionQueue = await waitForQueue(accessToken, 'facialRecognition', 300_000)",
    "smartQueue = await waitForQueue(accessToken, 'smartSearch', 300_000)"
)) {
    Assert-Protocol ($runtime.Contains($needle)) ('runtime_contract_missing_' + $assertions)
}
Assert-Protocol ($runtime.Contains("await startQueueIfIdle(accessToken, 'faceDetection')") `
    -and -not $runtime.Contains("'/assets/jobs', 'POST', { assetIds, name: 'refresh-faces' }")) 'face_jobs_must_not_be_double_enqueued'
Assert-Protocol ($runtime.Contains('`unexpected_${safeStage}`') `
    -and $runtime.Contains("runtimeStage = 'mounted_hash'") `
    -and $runtime.Contains("runtimeStage = 'queues'") `
    -and $runtime.Contains("runtimeStage = 'output'")) 'runtime_failure_stage_must_be_sanitized'
Assert-Protocol (@([regex]::Matches($runtime, 'console\.log\(`PRIVATE_QA_IMMICH_RUNTIME=FAIL reason=\$\{code\}`\);')).Count -eq 2 `
    -and -not $runtime.Contains('console.error(`PRIVATE_QA_IMMICH_RUNTIME=FAIL')) 'runtime_failure_marker_must_use_stdout'
foreach ($artifact in @('BINDINGS_PATH','INDEX_EVIDENCE_PATH')) {
    Assert-Protocol ($runtime.Contains("writePrivateJson($artifact")) ('runtime_private_artifact_missing_' + $artifact)
}
Assert-Protocol ($runtime.Contains("writePrivateText(SUMMARY_PATH") `
    -and $runtime.Contains("const SUMMARY_PATH = '/tmp/class-archive-private-qa-immich-runtime-summary.txt'")) 'runtime_summary_must_use_text_protocol'

Assert-Protocol ($runner.Contains("if (`$Action -ne 'finalize-indexes') {`r`n        `$script:stage = 'bridge_stager_start'") `
    -or $runner.Contains("if (`$Action -ne 'finalize-indexes') {`n        `$script:stage = 'bridge_stager_start'")) 'finalize_must_not_create_second_piwigo_token'
Assert-Protocol ($runner.Contains("if (`$Action -in @('provision', 'resume')) {`r`n            `$script:stage = 'canonical_bind'") `
    -or $runner.Contains("if (`$Action -in @('provision', 'resume')) {`n            `$script:stage = 'canonical_bind'")) 'finalize_must_not_rebind'
Assert-Protocol ($runner.Contains("`$runtimeEvidence.index_evidence.queue_idle.smart_search -eq `$true")) 'runtime_queue_evidence_required'
Assert-Protocol ($runner.Contains("'complete-indexes'")) 'v15_completion_invocation_missing'
Assert-Protocol ($runner.Contains('Get-Content -LiteralPath $modelManifest -Raw -Encoding UTF8')) 'model_manifest_utf8_decode_required'
Assert-Protocol ($runner.Contains("`$runtimeCommand = 'exec node ' + `$runtimeScriptContainer + ' --input-file ' + `$runtimeInputContainer + ' 2>&1'")) 'runtime_marker_must_avoid_ps51_native_stderr'
Assert-Protocol ($runner.Contains("`$nativeError = [string]`$_.Exception.Message") `
    -and $runner.Contains("`$safeInput = [string]::Join") `
    -and $runner.Contains("Fail 'immich_compose_failed'")) 'runtime_native_exception_must_remain_sanitized'
foreach ($stage in @('ml_runtime_execute','ml_runtime_marker','ml_runtime_output_copy','ml_runtime_output_acl','ml_runtime_output_read','ml_runtime_output_contract','ml_runtime_access_token')) {
    Assert-Protocol ($runner.Contains("`$script:stage = '$stage'")) ('runtime_safe_stage_missing_' + $stage)
}
Assert-Protocol ($runner.Contains('[IO.File]::ReadAllBytes($nodeOutputHost)') `
    -and $runner.Contains('[Text.UTF8Encoding]::new($false, $true).GetString($runtimeBytes)') `
    -and $runner.Contains('$runtimeBytes.Length -gt 128KB') `
    -and $runner.Contains("`$runtimeReadStep = 'line_endings'") `
    -and $runner.Contains("`$runtimeReadStep = 'keys'") `
    -and $runner.Contains("`$runtimeReadStep = 'values'") `
    -and $runner.Contains("`$runtimeReadStep = 'number_projection'") `
    -and $runner.Contains("`$runtimeReadStep = 'root_projection'") `
    -and $runner.Contains("'^([A-Z][A-Z0-9_]*)=(.*)$'") `
    -and $runner.Contains("'ACCESS_TOKEN'") `
    -and $runner.Contains("'TIMING_TOTAL'") `
    -and -not $runner.Contains('System.Web.Script.Serialization.JavaScriptSerializer') `
    -and $runner.Contains("Fail ('runtime_output_' + `$runtimeReadStep + '_invalid')")) 'runtime_output_must_use_strict_utf8'
Assert-Protocol ($runner.Contains("'runtime-summary.txt'") `
    -and $runner.Contains("'bindings.json'") `
    -and $runner.Contains("'index-evidence.json'") `
    -and $runner.Contains('[int]$runtimeEvidence.asset_count -eq [int]$catalog.count') `
    -and -not $runner.Contains('@($runtimeEvidence.assets)')) 'large_runtime_assets_must_bypass_ps_json'
Assert-Protocol ($runner.Contains('$runtimeEvidence = [ordered]@{') `
    -and -not [regex]::IsMatch($runner, '(?im)^\s*\$runtime\s*=')) 'runtime_parameter_must_not_be_overwritten'
$runtimeCollisionProbe = & {
    param([ValidateSet('qa', 'full')][string]$Runtime = 'full')
    $runtimeEvidence = [ordered]@{ version = 1 }
    if ($Runtime -ne 'full' -or $runtimeEvidence.version -ne 1) { return 'FAIL' }
    return 'PASS'
}
Assert-Protocol ($runtimeCollisionProbe -eq 'PASS') 'runtime_parameter_collision_regression'
Assert-Protocol ($runtime.Contains('async function allVisiblePeople(token, maximumPeople)') `
    -and $runtime.Contains('typeof hasNextPage !== ''boolean''') `
    -and $runtime.Contains("page += 1;") `
    -and $runtime.Contains("runtimeScope === 'PRIVATE_REAL_FULL' ? 5000 : 500")) 'runtime_people_pagination_missing'
Assert-Protocol ($bridge.Contains('const PEOPLE_PAGE_SIZE = 1000;') `
    -and $bridge.Contains('const MAX_PEOPLE_PAGES = 5;') `
    -and $bridge.Contains('async function allVisiblePeople()') `
    -and $bridge.Contains('typeof hasNextPage !== ''boolean''') `
    -and $bridge.Contains('const people = await allVisiblePeople();')) 'bridge_people_pagination_missing'
Assert-Protocol ($bridgeAdapter.Contains('count($items) > 5000')) 'bridge_adapter_people_page_limit_invalid'
Assert-Protocol ($bridgeAdapter.Contains('$clusters[$immichPersonId]') `
    -and $bridgeAdapter.Contains("ksort(`$clusters, SORT_STRING)") `
    -and $bridgeAdapter.Contains('if ($totalMemberships > 50000)')) 'bridge_adapter_people_batches_must_merge'

$credentialReset = $runner.IndexOf("Reset-ImmichAdminPassword -HostInput `$passwordResetHost -Password `$technicalPassword", [StringComparison]::Ordinal)
$tokenExport = $runner.IndexOf("`$script:stage = 'bridge_token_export'", [StringComparison]::Ordinal)
$gatewayStop = $runner.IndexOf("`$script:stage = 'gateway_stop_for_secret_rotation'", [StringComparison]::Ordinal)
$secretPublish = $runner.IndexOf("`$script:stage = 'bridge_rotation_publish'", [StringComparison]::Ordinal)
$gatewayRestart = $runner.IndexOf("`$script:stage = 'gateway_restart_after_secret_rotation'", [StringComparison]::Ordinal)
$bridgeProbe = $runner.LastIndexOf("`$script:stage = 'bridge_probe'", [StringComparison]::Ordinal)
Assert-Protocol ($credentialReset -ge 0 -and $tokenExport -gt $credentialReset -and $gatewayStop -gt $tokenExport `
    -and $secretPublish -gt $gatewayStop -and $gatewayRestart -gt $secretPublish -and $bridgeProbe -gt $gatewayRestart) 'finalize_secret_rotation_order_invalid'
Assert-Protocol ($runner.Contains("if (`$Action -in @('provision', 'resume', 'finish', 'finalize-indexes')) {`r`n        `$mutatingOperationLock = Enter-MutatingOperationLock") `
    -or $runner.Contains("if (`$Action -in @('provision', 'resume', 'finish', 'finalize-indexes')) {`n        `$mutatingOperationLock = Enter-MutatingOperationLock")) 'mutating_operation_single_instance_lock_missing'
Assert-Protocol ($runner.Contains('[IO.FileShare]::None') `
    -and $runner.IndexOf('Assert-IgnoredOwnerOnly $path', [StringComparison]::Ordinal) -lt $runner.IndexOf('[IO.FileShare]::None', [StringComparison]::Ordinal)) 'finalize_lock_acl_order_invalid'
Assert-Protocol ($runner.Contains("'running|none|null'") `
    -and $runner.Contains("'running|none|{}'") `
    -and $runner.Contains('bridge_stager_boundary_invalid')) 'rotation_stager_must_be_no_network'
Assert-Protocol ($runner.Contains("Invoke-BridgeStagerScript `$rotationScript 'bridge_rotation_publish_failed'") `
    -and $runner.Contains("fs.renameSync(next,current)") `
    -and $runner.Contains("createHash('sha256')") `
    -and $runner.Contains("readdirSync('/run/secrets').sort().join(',')!=='bridge.json'")) 'gateway_secret_atomic_rotation_contract_missing'
$nextRead = $runner.IndexOf("const raw=fs.readFileSync(next)", [StringComparison]::Ordinal)
$nextChown = $runner.IndexOf("fs.chownSync(next,65532,65532)", [StringComparison]::Ordinal)
Assert-Protocol ($nextRead -ge 0 -and $nextChown -gt $nextRead `
    -and -not $runner.Contains("readFileSync(current")) 'stager_must_not_read_unprivileged_secret_after_chown'
Assert-Protocol ($runner.Contains('Ensure-FinalizeStagerStopped') `
    -and $runner.Contains('docker stop -t 5 $name') `
    -and $runner.Contains('docker kill $name') `
    -and $runner.Contains('bridge_stager_cleanup_unproven')) 'rotation_stager_failure_cleanup_missing'
Assert-Protocol ($runner.Contains('Ensure-FinalizeGatewayFailClosed') `
    -and $runner.Contains('if ($Action -eq ''finalize-indexes'') { $finalizeOperationVerified = $true }') `
    -and $runner.Contains('$finalizeOperationVerified = $false')) 'finalize_failure_must_stop_gateway'
Assert-Protocol ($runner.Contains('Remove-FinalizeBridgeTransients') `
    -and $runner.Contains("test ! -e ' + `$bridgeTokenContainer") `
    -and $runner.Contains('if ($null -ne $bridgeCleanupFailure)')) 'finalize_bridge_transient_cleanup_missing'
Assert-Protocol ($runner.Contains("`$bridgeTokenExport.scope -ceq `$runtimeScope") `
    -and $runner.Contains("`$bridgeTokenExport.catalog_digest -ceq [string]`$catalog.catalog_digest") `
    -and $runner.Contains("[int]`$probeMatch.Groups[1].Value -eq [int]`$catalog.count") `
    -and $runner.Contains("[int]`$probeMatch.Groups[2].Value -eq [int]`$runtimeEvidence.metrics.people_count")) 'bridge_export_and_probe_binding_missing'
Assert-Protocol ($catalog.Contains("'export-bridge-token'") `
    -and -not $catalog.Contains("'rotate-bridge'") `
    -and $catalog.Contains('if (!$privateFullRuntime)') `
    -and $catalog.Contains('privateQaImmichReadDurableBridgeSecret(PRIVATE_QA_BRIDGE_SECRET)') `
    -and $catalog.Contains("'scope' => PRIVATE_IMMICH_SCOPE") `
    -and $catalog.Contains("'catalog_digest' => `$catalog['catalog_digest']")) 'piwigo_bridge_token_export_contract_missing'
Assert-Protocol ($catalog.Contains('$mode === 0600') `
    -and $catalog.Contains('$mode === 0660') `
    -and $catalog.Contains("privateQaImmichServiceId('PIWIGO_UID')") `
    -and $catalog.Contains("privateQaImmichServiceId('PIWIGO_GID')") `
    -and $catalog.Contains("(int) (`$stat['nlink'] ?? 0) !== 1") `
    -and $catalog.Contains("privateQaImmichExactKeys(`$decoded, ['version', 'token'])") `
    -and $catalog.Contains('stream_get_contents($handle, 513)') `
    -and $catalog.Contains("`$opened = fstat(`$handle)") `
    -and $catalog.Contains("`$after = fstat(`$handle)")) 'piwigo_existing_bridge_secret_validation_missing'
Assert-Protocol ($catalog.Contains("((int) (`$stat['mode'] ?? 0) & 0777) !== 0600") `
    -and $catalog.Contains("(int) (`$stat['size'] ?? -1) !== `$length")) 'piwigo_export_post_write_validation_missing'

foreach ($needle in @(
    "PRIVATE_IMMICH_SCOPE !== 'PRIVATE_REAL_FULL'",
    "getenv('CLASS_ARCHIVE_PRIVATE_AI_INDEX_WORKER')",
    'enqueueImportedActivePhotos()',
    'claimNextJob()',
    'completeIndexJob(',
    "`$status['state'] ?? null) !== 'READY'",
    "`$maintenance['missing_index_rows'] ?? null) !== 0",
    "`$maintenance['checksum_drift'] ?? null) !== 0"
)) {
    Assert-Protocol ($catalog.Contains($needle)) ('catalog_completion_contract_missing_' + $assertions)
}

Write-Output "PRIVATE_FULL_AI_PROTOCOL=PASS assertions=$assertions"
