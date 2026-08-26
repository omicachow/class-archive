<?php

declare(strict_types=1);

/**
 * Public-safe source contract for persistent Memory / AutoCollection control
 * flow. It opens no database, model cache, private staging or media file.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'service' => $root . '/plugins/ClassIdentity/src/AutoCollectionService.php',
    'main' => $root . '/plugins/ClassIdentity/main.inc.php',
    'builder' => $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionBuilder.php',
    'store' => $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php',
    'reconciliation' => $root . '/plugins/ClassIdentity/src/ReconciliationService.php',
    'gateway' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php',
    'controller' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
    'schema' => $root . '/plugins/ClassIdentity/src/Schema.php',
];
$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "AUTO_COLLECTION_STATIC=FAIL missing={$name}\n");
        exit(1);
    }
    $source[$name] = $contents;
}

$functionBody = static function (string $php, string $name): string {
    $start = strpos($php, 'function ' . $name . '(');
    if ($start === false) {
        return '';
    }
    $brace = strpos($php, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    for ($index = $brace, $length = strlen($php); $index < $length; ++$index) {
        if ($php[$index] === '{') {
            ++$depth;
        } elseif ($php[$index] === '}' && --$depth === 0) {
            return substr($php, $brace, $index - $brace + 1);
        }
    }
    return '';
};

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(str_contains($source['main'], "'src/AutoCollectionService.php'"), 'plugin_bootstrap_missing_auto_collection_service');
foreach (['auto_collection', 'auto_collection_photo', 'collection_kind', 'visibility_scope', 'projection_revision'] as $needle) {
    $assert(str_contains($source['schema'], $needle), 'v15_schema_missing_' . $needle);
}
$assert(str_contains($source['schema'], 'UNIQUE KEY `uq_ci_auto_collection_source_reason` (`source_reason`)'),
    'v15_source_reason_must_be_single_row_identity');
$assert(!str_contains($source['schema'], 'uq_ci_auto_collection_reason_revision'),
    'v15_must_not_retain_source_reason_revision_history_unique');
foreach (['syncMemoryProjection', 'normalizeMemoryProjection', 'assertDesiredPhotosActive', 'replaceMembers', 'read_only'] as $needle) {
    $assert(str_contains($source['service'], $needle), 'service_contract_missing_' . $needle);
}
foreach (['curl_', 'file_get_contents(', 'fopen(', 'copy(', 'hash_file(', 'exec(', 'shell_exec('] as $forbidden) {
    $assert(!str_contains($source['service'], $forbidden), 'service_must_not_read_media_or_network_' . $forbidden);
}

$builderSync = strpos($source['builder'], 'syncMemoryProjectionInCurrentTransaction');
$builderWrite = strrpos($source['builder'], '$store->rebuildAggregates(');
$assert($builderSync !== false && $builderWrite !== false && $builderSync < $builderWrite, 'memory_sync_not_registered_before_projection_publish');
$assert(str_contains($source['builder'], 'AUTO_COLLECTION_REBUILD_STARTED'), 'memory_publish_barrier_does_not_preinvalidate');
$aggregatePublish = $functionBody($source['store'], 'rebuildAggregates');
$callbackPosition = strpos($aggregatePublish, '$beforePublishInTransaction($this->repository)');
$activePosition = strpos($aggregatePublish, "SET `state`='ACTIVE'");
$assert($callbackPosition !== false && $activePosition !== false && $callbackPosition < $activePosition,
    'auto_collection_sync_not_inside_pre_publish_transaction');
$assert(str_contains($source['builder'], 'ReadProjectionStore::SCOPE_FULL'), 'memory_sync_not_bound_to_full_scope');
$assert(str_contains($source['builder'], '!$dryRun'), 'memory_sync_must_not_write_dry_run');

$memories = $functionBody($source['gateway'], 'memories');
$sanitizer = $functionBody($source['gateway'], 'publicMemoryPayload');
$home = $functionBody($source['gateway'], 'homeAggregateItems');
$homeRead = $functionBody($source['gateway'], 'home');
$assert($memories !== '' && str_contains($memories, 'publicMemoryPayload'), 'memory_get_not_sanitized');
$assert(!str_contains($memories, 'syncMemoryProjection') && !str_contains($memories, 'ReadProjectionBuilder::rebuild'), 'memory_get_must_not_write_or_rebuild');
$assert($homeRead !== '' && !str_contains($homeRead, 'syncMemoryProjection') && !str_contains($homeRead, 'ReadProjectionBuilder::rebuild'), 'home_get_must_not_write_or_rebuild');
$assert($sanitizer !== '' && str_contains($sanitizer, "'photo_ids'") && str_contains($sanitizer, "'source_reason'") && str_contains($sanitizer, "'projection_revision'"), 'memory_response_internal_fields_not_stripped');
$assert($home !== '' && str_contains($home, "'source_reason'") && str_contains($home, "'projection_revision'"), 'home_cards_internal_memory_fields_not_stripped');
$assert(!str_contains($source['controller'], 'AutoCollectionService'), 'http_controller_must_not_call_auto_collection_service');
$assert(str_contains($source['controller'], 'ReadProjectionStore::fromPiwigo()'), 'http_gateway_not_bound_to_read_projection');
$assert(substr_count($source['builder'], 'syncMemoryProjectionInCurrentTransaction') === 1, 'memory_sync_must_have_one_explicit_build_path');
$assert(!str_contains($source['gateway'], 'AutoCollectionService'), 'gateway_read_boundary_must_not_call_auto_collection_service');
$assert(str_contains($source['service'], "'scope' => 'FULL'") && str_contains($source['service'], 'memoryProjectionRevision'),
    'auto_collection_rows_not_bound_to_shared_full_revision');
$assert(str_contains($source['service'], "['UNKNOWN', 'EVENT_ONLY']") && str_contains($source['service'], "['EXACT', 'DAY', 'MONTH', 'YEAR']"),
    'term_without_exact_date_contract_missing');
foreach (['AUTO_COLLECTION_COVER_NOT_MEMBER', 'AUTO_COLLECTION_MEMBER_ORDINAL_DRIFT',
    'AUTO_COLLECTION_SOURCE_REASON_DUPLICATE', 'AUTO_COLLECTION_REVISION_DRIFT'] as $code) {
    $assert(str_contains($source['service'], "'{$code}'"), 'auto_collection_reconciliation_missing_' . $code);
    $assert(str_contains($source['reconciliation'], 'reconciliationReport'), 'reconciliation_not_using_auto_collection_report');
}
$assert(str_contains($source['service'], 'sort($actualReasonKeys, SORT_STRING)')
    && str_contains($source['service'], 'sort($expectedReasonKeys, SORT_STRING)'),
    'reconciliation_source_set_comparison_must_be_order_independent');

$windowsAbsolutePathPattern = '/[A-Za-z]:\\\\/';
foreach ($source as $name => $contents) {
    $assert(preg_match($windowsAbsolutePathPattern, $contents) !== 1, $name . '_contains_private_source_path');
    $assert(!str_contains($contents, '127.0.0.1:8191'), $name . '_couples_to_private_ui_port');
}

if ($failures !== []) {
    fwrite(STDERR, 'AUTO_COLLECTION_STATIC=FAIL assertions=' . $assertions . ' failures=' . implode(';', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "AUTO_COLLECTION_STATIC=PASS assertions={$assertions}\n");
