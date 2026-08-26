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
    "'/jobs/ocr', 'PUT', { command: 'pause' }",
    "'/jobs/ocr', 'PUT', { command: 'empty' }",
    "'/jobs/ocr', 'PUT', { command: 'clear-failed' }",
    "faceQueue = await waitForQueue(accessToken, 'faceDetection', 300_000)",
    "recognitionQueue = await waitForQueue(accessToken, 'facialRecognition', 300_000)",
    "smartQueue = await waitForQueue(accessToken, 'smartSearch', 300_000)"
)) {
    Assert-Protocol ($runtime.Contains($needle)) ('runtime_contract_missing_' + $assertions)
}

Assert-Protocol ($runner.Contains("if (`$Action -ne 'finalize-indexes') {`r`n        `$script:stage = 'bridge_stager_start'") `
    -or $runner.Contains("if (`$Action -ne 'finalize-indexes') {`n        `$script:stage = 'bridge_stager_start'")) 'finalize_must_not_reenable_bridge'
Assert-Protocol ($runner.Contains("if (`$Action -in @('provision', 'resume')) {`r`n            `$script:stage = 'canonical_bind'") `
    -or $runner.Contains("if (`$Action -in @('provision', 'resume')) {`n            `$script:stage = 'canonical_bind'")) 'finalize_must_not_rebind'
Assert-Protocol ($runner.Contains("`$runtime.index_evidence.queue_idle.smart_search -eq `$true")) 'runtime_queue_evidence_required'
Assert-Protocol ($runner.Contains("'complete-indexes'")) 'v15_completion_invocation_missing'

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
