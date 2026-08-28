<?php

declare(strict_types=1);

/**
 * Public-safe source contract for the explicit collection snapshot build
 * boundary. It opens no database, photo source, private staging or network.
 */

$root = dirname(__DIR__, 2);
$paths = [
    'builder' => $root . '/plugins/ClassIdentity/src/Gateway/CollectionSnapshotBuilder.php',
    'read_builder' => $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionBuilder.php',
    'store' => $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php',
    'snapshot_service' => $root . '/plugins/ClassIdentity/src/CollectionSnapshotService.php',
    'spotlight_rotation' => $root . '/plugins/ClassIdentity/src/SpotlightRotationService.php',
    'gateway' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php',
    'controller' => $root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php',
    'main' => $root . '/plugins/ClassIdentity/main.inc.php',
    'maintenance' => $root . '/infra/scripts/run-maintenance.php',
    'installer' => $root . '/infra/scripts/install-class-archive-plugins.php',
];
$source = [];
foreach ($paths as $name => $path) {
    $contents = file_get_contents($path);
    if (!is_string($contents) || $contents === '') {
        fwrite(STDERR, "COLLECTION_SNAPSHOT_BUILDER_STATIC=FAIL missing={$name}\n");
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
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};

$builder = $source['builder'];
foreach ([
    'CollectionSnapshotService::KIND_HOME',
    'CollectionSnapshotService::KIND_MEMORY',
    'CollectionSnapshotService::KIND_SPOTLIGHT',
    'CollectionSnapshotService::KIND_SEARCH_SUGGESTION',
    'ReadProjectionStore::SCOPE_FULL',
    'ReadProjectionStore::SCOPE_HERITAGE',
    'presentationEpoch($readScope)',
    '$snapshots->publishBundle($snapshotScope, $before, $itemsByKind)',
    'mixed Home (new hero with stale suggestions, for example)',
    'class_archive_collection_snapshot_source_epoch_changed',
    'CollectionSnapshotService::ITEM_SEARCH_SUGGESTION',
] as $needle) {
    $assert(str_contains($builder, $needle), 'builder_contract_missing_' . strtolower(str_replace(['::', '$', '(', ')', '[', ']'], '_', $needle)));
}
$assert(substr_count($builder, 'presentationEpoch($readScope)') >= 3,
    'builder_must_capture_and_recheck_exact_presentation_epoch');
$assert(str_contains($builder, 'assertAllScopesCurrent') && str_contains($builder, 'array_values($scopesByReadScope)'),
    'builder_must_recheck_full_and_heritage_epochs_together_before_pass');
$assert(!str_contains($builder, 'PiwigoGatewayAdapter') && !str_contains($builder, 'BridgeImmichAdapter'),
    'builder_must_not_walk_live_piwigo_or_immich');
foreach (['curl_', 'file_get_contents(', 'fopen(', 'copy(', 'hash_file(', 'exec(', 'shell_exec(', '$_GET', '$_POST'] as $forbidden) {
    $assert(!str_contains($builder, $forbidden), 'builder_must_not_use_io_or_request_input_' . strtolower(str_replace(['(', '$', '_'], '', $forbidden)));
}
foreach (['piwigo', 'immich', 'principal', 'account', 'seat', 'identity', 'storage', 'path', 'filename', 'token', 'secret', 'checksum', 'embedding', 'owner'] as $forbidden) {
    $assert(str_contains($source['snapshot_service'], "'{$forbidden}'"), 'snapshot_service_safe_payload_forbidden_key_missing_' . $forbidden);
}
$assert(str_contains($builder, "'section' => 'SPOTLIGHT'")
    && str_contains($builder, "'section' => 'RECOMMENDATION'")
    && str_contains($builder, "'section' => 'MEMORY'")
    && str_contains($builder, "'section' => 'ALBUM'")
    && str_contains($builder, "'section' => 'PERSON'")
    && str_contains($builder, "'section' => 'RECENT'"), 'builder_safe_section_payloads_missing');
$publicPayload = $functionBody($builder, 'assertPublicPayload');
$assert($publicPayload !== '' && str_contains($builder, 'Canonical item keys carry the opaque navigation handle')
    && !str_contains($builder, "'personId'") && !str_contains($publicPayload, 'personId'),
    'person_navigation_must_use_item_key_not_payload_identity');
$spotlightItems = $functionBody($builder, 'spotlightItems');
$assert($spotlightItems !== '' && str_contains($spotlightItems, '$items = $projection[\'items\'] ?? null;')
    && str_contains($spotlightItems, '$persistedOrder !== null')
    && str_contains($spotlightItems, 'class_archive_collection_snapshot_spotlight_rotation_invalid')
    && !str_contains($spotlightItems, '$total !== 1'),
    'builder_must_support_persisted_multi_spotlight_order');
$rebuildScope = $functionBody($builder, 'rebuildScope');
$assert($rebuildScope !== ''
    && str_contains($rebuildScope, 'advanceForMaintenance($rotationScope, $candidateIds)')
    && str_contains($rebuildScope, 'stateForPublishedCandidates($rotationScope, $candidateIds)')
    && str_contains($rebuildScope, "collection-snapshot-rotation-v1")
    && !str_contains($rebuildScope, '$_GET')
    && !str_contains($rebuildScope, '$_POST'),
    'builder_must_publish_server_owned_spotlight_rotation_without_request_input');
$assert(str_contains($builder, 'SpotlightRotationService::fromPiwigo()')
    && str_contains($builder, 'private static function spotlightRotationScope')
    && str_contains($builder, 'private static function spotlightCandidateIds'),
    'builder_must_wire_durable_rotation_service_only_on_write_side');
$assert(str_contains($source['spotlight_rotation'], 'stateForPublishedCandidates')
    && str_contains($source['spotlight_rotation'], 'class_archive_spotlight_rotation_state_stale')
    && str_contains($source['spotlight_rotation'], 'private static function serverNow'),
    'rotation_service_must_be_query_only_fail_closed_and_server_clock_owned');
$gatewaySource = $source['gateway'];
$assert(str_contains($gatewaySource, 'private function computeSpotlight(bool $requireCurrentUser): array')
    && str_contains($gatewaySource, '$items = [];')
    && str_contains($gatewaySource, '$items[] = [')
    && str_contains($gatewaySource, "return ['active' => true, 'total' => count(\$items), 'items' => \$items, 'item' => \$items[0]];")
    && str_contains($gatewaySource, 'private function validatedSpotlightProjection(array $projection): array')
    && str_contains($gatewaySource, '$items = $projection[\'items\'] ?? null;')
    && str_contains($gatewaySource, 'array_is_list($items)')
    && str_contains($gatewaySource, 'v16 aggregate rows contain the singleton')
    && str_contains($gatewaySource, 'never falls back to live source'),
    'gateway_spotlight_aggregate_must_preserve_all_active_records_and_read_legacy_fail_closed');
$searchSuggestions = $functionBody($builder, 'searchSuggestionItems');
$assert($searchSuggestions !== '' && str_contains($builder, 'Search suggestions are deliberately metadata-only')
    && substr_count($searchSuggestions, "\n                [],\n") >= 3,
    'search_suggestions_must_not_carry_photo_membership');
$assert(str_contains($builder, 'boundedWithCover') && str_contains($builder, 'MAX_ITEM_PHOTOS'),
    'snapshot_photo_membership_must_be_bounded');

$rebuild = $functionBody($source['read_builder'], 'rebuild');
$changed = $functionBody($source['read_builder'], 'rebuildChangedPhotos');
$assert($rebuild !== '' && str_contains($rebuild, 'self::rebuildAggregatesWithStore')
    && str_contains($rebuild, 'self::rebuildCollectionSnapshots($dryRun)'), 'full_read_build_must_publish_snapshots_after_aggregates');
$aggregatePosition = strpos($rebuild, 'self::rebuildAggregatesWithStore');
$snapshotPosition = strpos($rebuild, 'self::rebuildCollectionSnapshots($dryRun)');
$assert($aggregatePosition !== false && $snapshotPosition !== false && $aggregatePosition < $snapshotPosition,
    'snapshots_must_publish_after_aggregate_transaction');
$assert($changed !== '' && str_contains($changed, 'self::rebuildCollectionSnapshots(false)'),
    'incremental_read_build_must_refresh_snapshots');
$snapshotWrapper = $functionBody($source['read_builder'], 'rebuildCollectionSnapshots');
$assert($snapshotWrapper !== '' && str_contains($snapshotWrapper, 'CollectionSnapshotBuilder::rebuild()')
    && str_contains($snapshotWrapper, "'REVIEW_REQUIRED'") && !str_contains($snapshotWrapper, '$error->getMessage()'),
    'legacy_routes_must_receive_non_sensitive_review_signal_when_snapshot_build_fails');
$assert(str_contains($source['main'], "'src/Gateway/CollectionSnapshotBuilder.php'"), 'plugin_bootstrap_missing_snapshot_builder');
$assert(str_contains($source['installer'], "'src/Gateway/CollectionSnapshotBuilder.php'"), 'plugin_installer_missing_snapshot_builder');

$maintenance = $functionBody($source['maintenance'], 'maintenanceRun');
$assert($maintenance !== '' && str_contains($maintenance, "['collection_snapshots']")
    && str_contains($maintenance, 'collectionSnapshotMaintenanceAttention'),
    'maintenance_must_report_and_gate_snapshot_build');
$assert(str_contains($source['maintenance'], 'collectionSnapshotMaintenanceState'),
    'maintenance_snapshot_state_sanitizer_missing');

$gatewayHome = $functionBody($source['gateway'], 'collectionsHome');
$assert($gatewayHome !== '' && !str_contains($gatewayHome, 'CollectionSnapshotBuilder::rebuild')
    && !str_contains($gatewayHome, 'PiwigoGatewayAdapter::fromPiwigo'),
    'gateway_get_must_not_build_or_walk_live_source');
$assert(!str_contains($source['controller'], 'CollectionSnapshotBuilder'),
    'http_controller_must_not_build_snapshots');

$windowsAbsolutePathPattern = '/[A-Za-z]:\\\\/';
foreach ($source as $name => $contents) {
    if ($name !== 'installer') {
        $assert(preg_match($windowsAbsolutePathPattern, $contents) !== 1, $name . '_contains_private_source_path');
        $assert(!str_contains($contents, '127.0.0.1:8191'), $name . '_couples_to_private_ui_port');
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'COLLECTION_SNAPSHOT_BUILDER_STATIC=FAIL assertions=' . $assertions . ' failures=' . implode(';', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "COLLECTION_SNAPSHOT_BUILDER_STATIC=PASS assertions={$assertions}\n");
