<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/infra/scripts/warm-photo-cache.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$expects = static function (Closure $operation, string $code) use ($assert): void {
    try {
        $operation();
    } catch (Throwable $error) {
        $assert($error->getMessage() === $code, 'unexpected_error_' . $error->getMessage());
        return;
    }
    throw new RuntimeException('expected_error_' . $code);
};

$defaults = classArchivePhotoCacheArguments(['warm-photo-cache.php']);
$assert($defaults['scope'] === 'first-screen', 'default_scope_not_bounded');
$assert(
    $defaults['profiles'] === ['thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview'],
    'default_profiles_not_responsive_canonical_set',
);
$custom = classArchivePhotoCacheArguments([
    'warm-photo-cache.php',
    '--scope=covers',
    '--profiles=thumbnail,small,small',
    '--dry-run',
    '--json',
]);
$assert($custom['scope'] === 'covers', 'cover_scope_missing');
$assert($custom['profiles'] === ['thumbnail', 'small'], 'profiles_not_deduplicated');
$assert($custom['dry_run'] && $custom['json'], 'safe_output_flags_missing');
$recovery = classArchivePhotoCacheArguments([
    'warm-photo-cache.php',
    '--scope=all',
    '--profiles=square,thumbnail,xsmall,small,medium,large,preview',
    '--json',
]);
$assert($recovery['profiles'][0] === 'square' && count($recovery['profiles']) === 7, 'core_square_recovery_profile_missing');
$assert(classArchivePhotoCacheCompletesQueuedWarmup($defaults['profiles']), 'product_profiles_must_complete_queue');
$assert(classArchivePhotoCacheCompletesQueuedWarmup($recovery['profiles']), 'recovery_superset_must_complete_queue');
$assert(!classArchivePhotoCacheCompletesQueuedWarmup(['square', 'thumbnail', 'xsmall', 'small', 'medium', 'large']), 'incomplete_product_profiles_consumed_queue');
$expects(
    static fn (): array => classArchivePhotoCacheArguments(['warm-photo-cache.php', '--scope=private-path']),
    'photo_cache_scope_invalid',
);
$expects(
    static fn (): array => classArchivePhotoCacheArguments(['warm-photo-cache.php', '--profiles=custom']),
    'photo_cache_profile_invalid',
);
$expects(
    static fn (): array => classArchivePhotoCacheArguments(['warm-photo-cache.php', '--profiles=xsmall,']),
    'photo_cache_profiles_invalid',
);
$generatorEnvironment = classArchivePhotoCacheGeneratorEnvironment('upload/synthetic-sm.jpg');
$allowedGeneratorEnvironment = [
    'PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL', 'TZ',
    'CLASS_ARCHIVE_DERIVATIVE_GENERATOR', 'CLASS_ARCHIVE_DERIVATIVE_PATH',
    'QUERY_STRING', 'REQUEST_URI', 'SCRIPT_NAME', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL',
];
$assert(array_diff(array_keys($generatorEnvironment), $allowedGeneratorEnvironment) === []
    && ($generatorEnvironment['CLASS_ARCHIVE_DERIVATIVE_PATH'] ?? null) === 'upload/synthetic-sm.jpg', 'generator_environment_allowlist_invalid');

$source = file_get_contents(dirname(__DIR__, 2) . '/infra/scripts/warm-photo-cache.php');
$maintenance = file_get_contents(dirname(__DIR__, 2) . '/infra/scripts/run-maintenance.php');
$queue = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassArchivePolicy/src/DerivativeWarmupQueue.php');
$immediate = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassArchivePolicy/src/DerivativeCacheWarmer.php');
$mediaGuard = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassArchivePolicy/src/MediaGuard.php');
$nginx = file_get_contents(dirname(__DIR__, 2) . '/infra/piwigo-nginx/nginx.conf');
$submission = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/SubmissionService.php');
$privateImport = file_get_contents(dirname(__DIR__, 2) . '/infra/scripts/import-private-real-qa.php');
$startupHook = file_get_contents(dirname(__DIR__, 2) . '/infra/piwigo-config/user.sh');
$admin = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/AdminService.php');
$adminController = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassIdentity/admin.php');
$systemTemplate = file_get_contents(dirname(__DIR__, 2) . '/plugins/ClassIdentity/template/admin/system.tpl');
$assert(is_string($source) && is_string($maintenance) && is_string($queue) && is_string($immediate) && is_string($mediaGuard) && is_string($nginx)
    && is_string($submission) && is_string($privateImport) && is_string($startupHook) && is_string($admin)
    && is_string($adminController) && is_string($systemTemplate), 'warmup_sources_unreadable');
$classIdentityNamespace = chr(92) . 'ClassIdentity' . chr(92);
$gatewayNamespace = $classIdentityNamespace . 'Gateway' . chr(92);
$assert(str_contains($source, 'DerivativeImage::get_one'), 'piwigo_derivative_profile_not_reused');
$assert(str_contains($source, '/plugins/ClassArchivePolicy/derivative-generator.php'), 'piwigo_core_generator_not_reused');
$assert(str_contains($source, "posix_geteuid') && posix_geteuid() === 0"), 'root_execution_not_rejected');
$assert(str_contains($source, 'LOCK_EX | LOCK_NB'), 'single_instance_lock_missing');
$assert(str_contains($source, 'photo_cache_mapping_reference_drift'), 'active_mapping_drift_not_fail_closed');
$assert(str_contains($source, '@chmod($target[\'absolute\'], 0660)'), 'derivative_mode_not_enforced');
$assert(str_contains($source, 'classArchivePhotoCacheNormalizeSourceMetadata'), 'source_metadata_precompute_missing');
$assert(str_contains($source, '\\pwg_image::get_rotation_angle($source)'), 'piwigo_rotation_reader_not_reused');
$assert(str_contains($source, "'would_normalize_metadata'"), 'metadata_dry_run_evidence_missing');
$assert(str_contains($source, $gatewayNamespace . 'ReadProjectionBuilder::rebuild();') && !str_contains($source, $gatewayNamespace . 'ReadProjectionBuilder::rebuildChangedPhotos('), 'native_metadata_batch_must_publish_full_catalog_generation');
$assert(str_contains($source, $classIdentityNamespace . 'ProjectionMutationBoundary::allAggregateKinds()'), 'metadata_change_all_aggregate_dependencies_missing');
$assert(strpos($source, 'ReadProjectionBuilder::rebuild();') < strpos($source, '\\ClassArchiveDerivativeWarmupQueue::complete('), 'maintenance_marker_consumed_before_projection_recovery');
$assert(!str_contains($source, 'imagecreatefrom'), 'custom_resize_pipeline_detected');
$assert(str_contains($source, 'classArchivePhotoCacheGenerateIdentity'), 'tiny_source_maintenance_generation_missing');
$assert(str_contains($source, "'square' => 'IMG_SQUARE'")
    && str_contains($source, 'classArchivePhotoCacheCompletesQueuedWarmup'), 'core_square_must_not_expand_product_queue_contract');
$assert(str_contains($source, '\\ClassArchiveDerivativeWarmupQueue::pending()'), 'durable_approval_queue_not_consumed');
$assert(str_contains($source, '\\ClassArchiveDerivativeWarmupQueue::complete('), 'successful_queue_completion_missing');
$assert(str_contains($queue, "'class_photo_id' => \$classPhotoId, 'piwigo_image_id' => \$imageId"), 'queue_contains_noncanonical_identity');
$assert(!str_contains($queue, 'source_path') && !str_contains($queue, 'derivative_path'), 'queue_must_not_store_media_paths');
$assert(str_contains($submission, 'ClassArchiveDerivativeWarmupQueue::enqueueBestEffort('), 'submission_approval_warmup_handoff_missing');
$assert(str_contains($privateImport, 'ClassArchiveDerivativeWarmupQueue::enqueueBestEffort('), 'controlled_import_warmup_handoff_missing');
$assert(str_contains($privateImport, 'ReadProjectionBuilder::rebuild();'), 'controlled_import_must_publish_full_catalog_after_native_batch');
$assert(str_contains($submission, 'ClassArchiveDerivativeCacheWarmer::warmBestEffort('), 'submission_write_side_prewarm_missing');
$assert(str_contains($privateImport, 'ClassArchiveDerivativeCacheWarmer::warmBestEffort('), 'controlled_import_write_side_prewarm_missing');
$assert(strpos($submission, 'ClassArchiveDerivativeCacheWarmer::warmBestEffort(') > strpos($submission, '$this->repository->transaction('), 'submission_prewarm_inside_business_transaction');
$approvalStart = strpos($adminController, "case 'approve_submission':");
$approvalEnd = strpos($adminController, "case 'reject_submission':");
$assert($approvalStart !== false && $approvalEnd !== false && $approvalStart < $approvalEnd, 'submission_approval_controller_boundary_missing');
$approvalController = substr($adminController, $approvalStart, $approvalEnd - $approvalStart);
$assert(str_contains($approvalController, 'ReadProjectionBuilder::rebuild();')
    && !str_contains($approvalController, 'ReadProjectionBuilder::rebuildChangedPhotos('), 'submission_approval_new_native_image_must_publish_full_catalog');
$assert(str_contains($immediate, 'private const MAX_RUNTIME_SECONDS = 30.0'), 'immediate_prewarm_not_bounded');
$assert(str_contains($immediate, '$mode !== 0660 && (!@chmod('), 'persistent_lock_mode_not_safely_reused');
$assert(substr_count($immediate, '$mode !== 0660 && (!@chmod(') >= 2, 'persistent_derivative_mode_not_safely_reused');
$assert(str_contains($immediate, 'pm.`class_photo_id`=UNHEX(REPLACE(?,') && str_contains($immediate, 'pm.`piwigo_image_id`=?'), 'immediate_prewarm_not_bound_to_exact_mapping');
$assert(str_contains($immediate, 'ClassArchiveDerivativeWarmupQueue::complete('), 'immediate_success_does_not_complete_marker');
$assert(str_contains($immediate, 'ReadProjectionBuilder::rebuild();') && !str_contains($immediate, 'ReadProjectionBuilder::rebuildChangedPhotos('), 'single_photo_native_metadata_update_must_publish_full_catalog_generation');
$assert(str_contains($immediate, 'ProjectionMutationBoundary::invalidatePhotos(') && str_contains($immediate, 'ProjectionMutationBoundary::allAggregateKinds()'), 'single_photo_metadata_invalidation_dependencies_missing');
$assert(strpos($immediate, 'ReadProjectionBuilder::rebuild();') < strpos($immediate, 'ClassArchiveDerivativeWarmupQueue::complete('), 'marker_consumed_before_projection_recovery');
$assert(str_contains($immediate, '/plugins/ClassArchivePolicy/derivative-generator.php') && str_contains($immediate, 'new pwg_image($source)'), 'immediate_prewarm_not_reusing_piwigo_pipeline');
$assert(!str_contains($immediate, "\$_SERVER['REQUEST_METHOD']") && !str_contains($immediate, 'media-gateway.php'), 'member_request_dispatch_leaked_into_write_side_warmer');
$assert(str_contains($immediate, "get_class(\$error)") && !str_contains($immediate, "error_log(\$classPhotoId") && !str_contains($immediate, "error_log(\$relative"), 'immediate_prewarm_log_may_leak_identity_or_path');
$assert(str_contains($queue, '$ownerUid !== $directoryOwner') && str_contains($queue, '$ownerUid !== $effectiveUid'), 'durable_marker_owner_boundary_missing');
$assert(str_contains($queue, 'quarantineOrphan(') && str_contains($queue, "@rename(\$source, \$target)") && !str_contains($queue, '@unlink($source)'), 'verified_orphan_marker_not_preserved_in_quarantine');
$assert(str_contains($source, '$mappingRows === [] && $imageRows === []') && str_contains($source, '::quarantineOrphan('), 'orphan_quarantine_not_bound_to_two_absence_proofs');
$assert(str_contains($source, "'queue_quarantined' => count(\$quarantined)") && str_contains($source, "'queue_retained' => count(\$pending) - count(\$quarantined)"), 'quarantine_structured_result_missing');
$assert(str_contains($startupHook, "grep -Eq '^[0-9a-f]{12}[1-5]") && str_contains($startupHook, 'expected=$(printf'), 'legacy_marker_migration_not_exactly_validated');
$assert(str_contains($startupHook, 'chown "${PIWIGO_UID:-1000}:${PIWIGO_GID:-1000}" "$marker"') && !str_contains($startupHook, 'rm -f "$marker"'), 'legacy_marker_not_safely_migrated');
$assert(str_contains($mediaGuard, "throw new ClassArchiveMediaUnavailable('derivative_not_ready'"), 'member_cache_miss_not_explicitly_unavailable');
$assert(str_contains($mediaGuard, "(int) (\$stat['nlink'] ?? 0) !== 1") && str_contains($mediaGuard, "((\$stat['mode'] ?? 0) & 0777) !== 0660") && str_contains($mediaGuard, '$ownerUid !== $rootOwnerUid'), 'delivery_inode_owner_mode_contract_missing');
$assert(str_contains($source, 'classArchivePhotoCacheAssertTrustedFile(') && str_contains($immediate, 'private static function trustedFile('), 'warmer_inode_trust_contract_missing');
$assert(!str_contains($source, '$environment = getenv();') && !str_contains($immediate, '$environment = getenv();'), 'generator_inherits_full_secret_environment');
$assert(str_contains($source, 'CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_STDERR_LIMIT = 8192') && str_contains($immediate, 'private const MAX_STDERR_BYTES = 8192'), 'generator_stderr_not_bounded');
$assert(str_contains($source, 'CLASS_ARCHIVE_PHOTO_CACHE_GENERATOR_TIMEOUT_SECONDS = 30.0') && str_contains($immediate, 'private const MAX_RUNTIME_SECONDS = 30.0'), 'generator_runtime_not_bounded');
$assert(str_contains($source, '@proc_terminate($process, 9)') && str_contains($immediate, '@proc_terminate($process, 9)'), 'generator_timeout_has_no_forced_kill');
$assert(!str_contains($mediaGuard, '/_class_archive_internal/generate/'), 'member_guard_still_dispatches_generator');
$assert(!str_contains($nginx, '/_class_archive_internal/generate/'), 'nginx_request_time_generator_still_reachable');
$assert(str_contains($maintenance, "classArchivePhotoCacheWarm(\n            'first-screen'"), 'maintenance_first_screen_warmup_missing');
$assert(str_contains($maintenance, "classArchivePhotoCacheWarm(\n            'covers'"), 'maintenance_cover_warmup_missing');
$assert(str_contains($maintenance, "classArchivePhotoCacheWarm(\n            'all'")
    && str_contains($maintenance, "['square', 'thumbnail', 'xsmall', 'small', 'medium', 'large', 'preview']")
    && str_contains($maintenance, "'all_recovery' => ["), 'durable_all_mapping_recovery_missing');
$assert(str_contains($admin, '$cached + $generated !== $checked') && str_contains($admin, '$sourceReuse > $checked'), 'system_health_warmup_overlap_semantics_invalid');
$assert(str_contains($admin, '$selectedImages * (int) $definition[\'profile_count\']'), 'system_health_warmup_coverage_denominator_unbound');
$assert(str_contains($admin, 'self::percentageLabel($cached + $generated, $checked)')
    && str_contains($admin, 'self::percentageLabel($cached, $checked)')
    && str_contains($admin, 'self::percentageLabel($sourceReuse, $checked)'), 'system_health_warmup_rates_missing');
$assert(str_contains($admin, "'derivative_runtime_metrics_label' => '尚未采集'"), 'runtime_hit_rate_must_not_be_fabricated');
$assert(str_contains($systemTemplate, '预生成覆盖率') && str_contains($systemTemplate, '已有派生图复用率')
    && str_contains($systemTemplate, '原尺寸复用率'), 'system_health_warmup_chinese_copy_missing');
$assert(str_contains($systemTemplate, '运行时缓存命中 / 未命中：{$CA_SYSTEM.derivative_runtime_metrics_label')
    && str_contains($systemTemplate, '当前没有持久运行时计数'), 'system_health_runtime_metrics_disclosure_missing');
$assert(str_contains($admin, "'last_build_label'") && str_contains($admin, "'built_at'"), 'system_health_projection_build_time_missing');
$assert(str_contains($systemTemplate, '{$projection.state_label') && str_contains($systemTemplate, '{$projection.count_label')
    && str_contains($systemTemplate, '{$projection.last_build_label'), 'system_health_projection_rows_incomplete');
$assert(str_contains($systemTemplate, '无法确认读取投影状态时不会显示为正常'), 'system_health_projection_fail_closed_copy_missing');

defined('PHPWG_ROOT_PATH') || define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
require_once dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/AdminService.php';
$percentageLabel = new ReflectionMethod(ClassIdentityAdminService::class, 'percentageLabel');
$percentageLabel->setAccessible(true);
$assert($percentageLabel->invoke(null, 12, 12) === '100%', 'system_health_full_coverage_label_invalid');
$assert($percentageLabel->invoke(null, 1, 2) === '50%', 'system_health_reuse_rate_label_invalid');
$assert($percentageLabel->invoke(null, 0, 0) === '尚无可计算数据', 'system_health_empty_rate_must_be_uncollected');
$assert($percentageLabel->invoke(null, 3, 2) === '尚无可计算数据', 'system_health_invalid_rate_must_fail_closed');

fwrite(STDOUT, "PHOTO_CACHE_WARMUP_STATIC=PASS ASSERTIONS=82\n");
