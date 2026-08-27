<?php

declare(strict_types=1);

/**
 * Public-safe source contract for post-import delta AI/derivative work.
 * No Docker service, private database, model, source file or localhost
 * endpoint is opened by this test.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'operator' => $root . '/infra/scripts/private-full-incremental-media.ps1',
    'entrypoint' => $root . '/infra/scripts/private-full-immich.ps1',
    'runtime' => $root . '/infra/scripts/private-qa-immich-incremental-runtime.mjs',
    'catalog' => $root . '/infra/scripts/private-qa-immich-catalog.php',
    'warmer' => $root . '/infra/scripts/warm-photo-cache.php',
    'ai' => $root . '/plugins/ClassIdentity/src/AiIndexService.php',
    'gateway_get' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
    'upstream_jobs' => $root . '/infra/immich-spike/source/official-v3.1.0/server/src/repositories/asset-job.repository.ts',
    'upstream_people' => $root . '/infra/immich-spike/source/official-v3.1.0/server/src/services/person.service.ts',
];
$source = [];
foreach ($paths as $name => $path) {
    $value = file_get_contents($path);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "PRIVATE_INCREMENTAL_MEDIA_PROTOCOL=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $value;
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $code) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $code;
    }
};

$assert(str_contains($source['entrypoint'], "'sync-incremental'"), 'operator_action_missing');
$assert(str_contains($source['entrypoint'], 'private-full-incremental-media.ps1'), 'operator_delegate_missing');
$assert(str_contains($source['operator'], "[ValidateSet('full', 'restore')]"), 'runtime_scope_not_closed');
$assert(str_contains($source['operator'], "[ValidateSet('validate', 'plan', 'apply')]"), 'operator_action_not_closed');
$assert(str_contains($source['operator'], '[IO.FileShare]::None'), 'single_instance_lock_missing');
$assert(str_contains($source['operator'], "[string]\$core[0].HostIp -eq '127.0.0.1'")
    && str_contains($source['operator'], "[string]\$compat[0].HostIp -eq '127.0.0.1'")
    && str_contains($source['operator'], 'ConvertFrom-Json -ErrorAction Stop'),
    'loopback_port_binding_not_structurally_verified');
$assert(str_contains($source['operator'], 'if ($deltaCount -eq 0)')
    && str_contains($source['operator'], 'no_op=1')
    && str_contains($source['operator'], 'queues_started = 0'), 'verified_noop_missing');
$assert(str_contains($source['operator'], "\$requireFullDerivativeCache = \$Runtime -eq 'full'")
    && str_contains($source['operator'], "if (\$requireFullDerivativeCache) { Get-Warmup 'all' \$true } else { \$null }")
    && str_contains($source['operator'], "if (\$requireFullDerivativeCache) {\n        \$postwarmAll = Get-Warmup 'all' \$true"),
    'full_runtime_derivative_attestation_not_isolated');
$assert(str_contains($source['operator'], "Get-Warmup 'exact' \$true '' \$exactManifestDigest ([string]\$plan.delta_digest)")
    && str_contains($source['operator'], '[int]$completedPrewarm.selected_images -eq $completedDerivativeCount')
    && str_contains($source['operator'], '[int]$completedPrewarm.cached -eq $completedDerivativeCount * $profiles')
    && str_contains($source['operator'], '[int]$completedPrewarm.would_generate -eq 0')
    && str_contains($source['operator'], 'derivative_restore_completed_not_ready'),
    'restore_completed_delta_exact_verifier_missing');
$assert(str_contains($source['operator'], 'completed-derivatives.json')
    && str_contains($source['operator'], '(Get-FileHash -LiteralPath $exactHost -Algorithm SHA256).Hash.ToLowerInvariant()')
    && str_contains($source['operator'], 'delta_digest = [string]$plan.delta_digest'),
    'restore_completed_manifest_not_plan_and_sha_bound');
$assert(str_contains($source['operator'], 'all-delta-derivatives.json')
    && str_contains($source['operator'], '[int]$postExact.selected_images -eq $deltaCount')
    && str_contains($source['operator'], '[int]$postExact.cached -eq $deltaCount * $profiles')
    && str_contains($source['operator'], 'derivative_restore_post_delta_not_ready'),
    'restore_post_drain_exact_delta_attestation_missing');
$assert(str_contains($source['operator'], "Get-Warmup 'queue' \$false"), 'delta_derivative_action_missing');
$assert(str_contains($source['operator'], '[int]$prewarm.cached - [int]$deltaPrewarm.cached -eq ($baselineCount + $completedDerivativeCount) * $profiles')
    && str_contains($source['operator'], 'derivative_full_cache_delta_mismatch'), 'full_baseline_and_retry_ready_not_proven');
$assert(str_contains($source['operator'], '[int]$warm.selected_images -eq $pendingDerivativeCount'), 'derivative_delta_not_exact');
$assert(str_contains($source['operator'], "Get-Warmup 'queue' \$false ([string]\$deltaPrewarm.queue_digest)")
    && str_contains($source['warmer'], 'hash_equals($expectedQueueDigest, $queueDigest)'),
    'derivative_queue_race_not_bound');
$assert(str_contains($source['operator'], '$deltaDerivativeKeys.ContainsKey($key)')
    && str_contains($source['operator'], 'derivative_queue_outside_delta'), 'derivative_queue_subset_not_proven');
$derivativeStage = strpos($source['operator'], "\$script:stage = 'derivative_delta'");
$commitStage = strpos($source['operator'], "\$script:stage = 'index_control_plane_commit'");
$assert($derivativeStage !== false && $commitStage !== false && $derivativeStage < $commitStage,
    'derivative_failure_not_retryable_before_control_commit');
$assert(str_contains($source['operator'], 'if ($requireFullDerivativeCache) {')
    && str_contains($source['operator'], "Get-Warmup 'all' \$true")
    && str_contains($source['operator'], '[int]$postwarmAll.cached -eq $catalogCount * $profiles'),
    'full_post_derivative_library_readiness_missing');
$assert(str_contains($source['operator'], 'cannot select any baseline item')
    && str_contains($source['operator'], 'ordinary GET cannot perform this action'),
    'restore_baseline_or_get_nonselection_not_documented');
$assert(str_contains($source['operator'], 'derivative_old_selected=0'), 'old_derivative_nonexecution_not_reported');
$assert(str_contains($source['operator'], 'Get-ImmichIndexSnapshot @($plan.baseline) \'baseline-before\'')
    && str_contains($source['operator'], 'Get-ImmichIndexSnapshot @($plan.baseline) \'baseline-after\'')
    && str_contains($source['operator'], 'Get-ImmichIndexSnapshot $deltaBindings \'delta-after\''),
    'immich_db_delta_attestation_missing');
$assert(str_contains($source['operator'], 'ai_baseline_reprocessed')
    && str_contains($source['operator'], '[int]$deltaAfterDb.status_ready -eq $deltaCount')
    && str_contains($source['operator'], '[int]$deltaAfterDb.smart_ready -eq $deltaCount'),
    'immich_baseline_or_delta_readiness_not_proven');
$assert(str_contains($source['operator'], 'FROM asset_face f INNER JOIN target')
    && str_contains($source['operator'], 'FROM smart_search s INNER JOIN target')
    && str_contains($source['operator'], 'FROM asset_job_status s INNER JOIN target')
    && str_contains($source['operator'], "'face_embedding_required'")
    && str_contains($source['operator'], "'face_embedding_ready'")
    && str_contains($source['operator'], '[int]$deltaAfterDb.face_embedding_ready -eq [int]$deltaAfterDb.face_embedding_required'),
    'immich_index_snapshot_incomplete');
$assert(!str_contains($source['operator'], 'media_reference') && !str_contains($source['operator'], 'originalPath'), 'aggregate_operator_leaks_media_reference');

$assert(str_contains($source['runtime'], "{ command: 'start', force: false }"), 'missing_only_queue_not_used');
$assert(!str_contains($source['runtime'], "{ command: 'start', force: true }"), 'whole_library_queue_forbidden');
$assert(!str_contains($source['runtime'], "startMissingOnlyQueue(token, 'facialRecognition')"), 'global_unassigned_face_scan_forbidden');
$assert(str_contains($source['runtime'], "'library', 'metadataExtraction', 'thumbnailGeneration', 'faceDetection', 'facialRecognition', 'smartSearch'")
    && str_contains($source['runtime'], 'await requireQueueIdle(token, queue)')
    && str_contains($source['runtime'], "await waitQueue(token, 'thumbnailGeneration'"),
    'incremental_queue_window_not_isolated');
$assert(str_contains($source['runtime'], 'baselineRuntimeDigest(plan, beforeByPath, photoById)'), 'baseline_runtime_before_missing');
$assert(str_contains($source['runtime'], 'baselineRuntimeDigest(plan, afterByPath, photoById)'), 'baseline_runtime_after_missing');
$assert(str_contains($source['runtime'], 'if (baselineBefore !== baselineAfter)'), 'baseline_runtime_change_not_fail_closed');
$assert(str_contains($source['runtime'], 'old_asset_changes: 0'), 'old_asset_change_evidence_missing');
$assert(str_contains($source['runtime'], "runtime_mode: 'INCREMENTAL'"), 'incremental_runtime_mode_missing');
$assert(str_contains($source['runtime'], "const API = 'http://immich-server:2283/api'"), 'internal_api_authority_missing');
$assert(!str_contains($source['runtime'], '127.0.0.1:8191') && !str_contains($source['runtime'], '127.0.0.1:8291'), 'runtime_coupled_to_browser_port');
$assert(!str_contains($source['runtime'], 'createReadStream')
    && !str_contains($source['runtime'], 'readFileSync(photo.path)')
    && !str_contains($source['runtime'], 'hash_file('), 'gateway_delta_runner_must_not_read_originals');

$assert(str_contains($source['catalog'], "'export-incremental'"), 'incremental_plan_action_missing');
$assert(str_contains($source['catalog'], "'complete-incremental'"), 'incremental_completion_action_missing');
$assert(str_contains($source['catalog'], 'privateQaImmichIncrementalPlan'), 'incremental_plan_builder_missing');
$assert(str_contains($source['catalog'], "ClassIdentity\\AiIndexService::TRIGGER_NEW_PHOTO"), 'new_photo_trigger_missing');
$assert(str_contains($source['catalog'], "ClassIdentity\\AiIndexService::TRIGGER_PIXEL_CHANGED"), 'pixel_change_trigger_missing');
$assert(str_contains($source['catalog'], "ClassIdentity\\AiIndexService::JOB_PENDING"), 'pending_only_contract_missing');
$assert(substr_count($source['catalog'], 'not_before`<=UTC_TIMESTAMP(6)') >= 2
    && substr_count($source['catalog'], "(int) (\$job['eligible'] ?? 0) !== 1") >= 2,
    'deferred_job_bypassed');
$assert(str_contains($source['catalog'], 'count($delta) > 512')
    && !str_contains($source['catalog'], 'count($delta) < 1'), 'zero_delta_noop_not_supported');
$assert(str_contains($source['catalog'], "(\$input['force_full'] ?? null) !== false"), 'force_full_evidence_not_rejected');
$assert(str_contains($source['catalog'], 'privateQaImmichBaselineDigest'), 'class_archive_baseline_digest_missing');
$completeStart = strpos($source['catalog'], "if (\$action === 'complete-incremental')");
$completeEnd = $completeStart === false ? false : strpos($source['catalog'], "if (\$action === 'export-bridge-token')", $completeStart);
$completeBody = $completeStart !== false && $completeEnd !== false
    ? substr($source['catalog'], $completeStart, $completeEnd - $completeStart)
    : '';
$assert($completeBody !== '', 'incremental_completion_body_missing');
$assert(!str_contains($completeBody, 'enqueueImportedActivePhotos()'), 'incremental_completion_scans_whole_library');
$assert(!str_contains($completeBody, 'requestAdminReindex'), 'incremental_completion_admin_reindex_forbidden');
$assert(str_contains($completeBody, '$completed !== $plan[\'delta_count\']'), 'completed_delta_count_not_exact');
$assert(str_contains($completeBody, '$repository->transaction(function')
    && str_contains($completeBody, 'ORDER BY `class_photo_id`,`job_id` FOR UPDATE')
    && str_contains($completeBody, 'AND `immich_asset_id` IS NULL'), 'control_plane_delta_not_atomic');

$assert(substr_count($source['warmer'], "['first-screen', 'covers', 'queue', 'exact', 'all']") >= 2, 'queue_scope_not_closed');
$assert(str_contains($source['warmer'], "\$queueOnlyFilter = in_array(\$scope, ['queue', 'exact'], true) ? ' AND 1=0' : ''"), 'queue_and_exact_scope_base_relation_not_empty');
$assert(str_contains($source['warmer'], "in_array(\$scope, ['queue', 'all'], true)"), 'queue_scope_durable_markers_missing');
$assert(str_contains($source['warmer'], '\\ClassArchiveDerivativeWarmupQueue::complete('), 'queue_completion_missing');
$assert(str_contains($source['warmer'], "'queue_entries' => \$queueEntries")
    && !str_contains($source['warmer'], "'queue_entries' => \$rows"), 'queue_retry_evidence_leaks_or_missing');
$assert(str_contains($source['warmer'], "'queue_digest' => \$queueDigest"), 'queue_apply_digest_missing');
$assert(str_contains($source['warmer'], 'CLASS_ARCHIVE_PHOTO_CACHE_EXACT_MANIFEST')
    && str_contains($source['warmer'], 'hash_equals($manifestDigest, hash(\'sha256\', $raw))')
    && str_contains($source['warmer'], 'hash_equals($deltaDigest, (string) $manifest[\'delta_digest\'])')
    && str_contains($source['warmer'], "if (!\$dryRun || \$expectedQueueDigest !== null")
    && str_contains($source['warmer'], 'photo_cache_exact_mapping_unresolved'),
    'exact_completed_scope_not_bounded_read_only_and_digest_bound');

$assert(str_contains($source['ai'], 'Ordinary GET') || str_contains($source['ai'], 'ordinary photo read path'), 'read_path_separation_not_documented');
$assert(str_contains($source['ai'], 'requirePrivateWorker'), 'private_worker_gate_missing');
$assert(!str_contains($source['gateway_get'], 'sync-incremental')
    && !str_contains($source['gateway_get'], 'export-incremental')
    && !str_contains($source['gateway_get'], 'complete-incremental')
    && !str_contains($source['gateway_get'], 'warm-photo-cache.php'), 'ordinary_get_triggers_incremental_work');
$assert(str_contains($source['upstream_jobs'], 'streamForEncodeClip(force?: boolean)'), 'pinned_clip_stream_contract_missing');
$assert(str_contains($source['upstream_jobs'], 'eb.not((eb) => eb.exists(eb.selectFrom(\'smart_search\')'), 'clip_missing_only_filter_missing');
$assert(str_contains($source['upstream_jobs'], 'streamForDetectFacesJob(force?: boolean)'), 'pinned_face_stream_contract_missing');
$assert(str_contains($source['upstream_jobs'], "force === false") && str_contains($source['upstream_jobs'], "facesRecognizedAt', 'is', null"), 'face_missing_only_filter_missing');
$assert(str_contains($source['upstream_people'], "{ name: JobName.FacialRecognitionQueueAll, data: { force: false } }, ...jobs")
    && str_contains($source['upstream_people'], 'else if (waiting)')
    && str_contains($source['upstream_people'], "{ personId: null, sourceType: SourceType.MachineLearning }"),
    'new_face_recognition_delta_guard_missing');

foreach ($source as $name => $contents) {
    $assert(preg_match('/[A-Za-z]:\\\\/', $contents) !== 1, "private_absolute_path_{$name}");
}

if ($failures !== []) {
    fwrite(STDERR, 'PRIVATE_INCREMENTAL_MEDIA_PROTOCOL=FAIL assertions=' . $assertions
        . ' failures=' . implode(';', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PRIVATE_INCREMENTAL_MEDIA_PROTOCOL=PASS assertions={$assertions} evidence=STATIC_SYNTHETIC_ONLY\n");
