<?php

declare(strict_types=1);

/**
 * Static public-boundary contract for the small Phase 3.4 collection
 * preference workflow. It deliberately has no database/runtime dependency:
 * schema v17 already owns the durable pin/feedback tables, and this test
 * proves the new read projection does not fabricate a separate client store
 * or a broad album/AutoCollection mutation surface.
 */

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if (!is_string($contents)) {
        throw new RuntimeException('collection_feedback_contract_source_unavailable');
    }
    return $contents;
};

$snapshot = $read('plugins/ClassIdentity/src/CollectionSnapshotService.php');
$gateway = $read('plugins/ClassIdentity/src/Gateway/GatewayService.php');
$controller = $read('plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
$bff = $read('infra/immich-spike/web-compat/server.mjs');
$ui = $read('infra/immich-spike/photo-ui/app.js');

$assertions = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$assertions;
};

try {
    $assert(str_contains($snapshot, 'private const MAX_FEEDBACK = 4000;'), 'feedback_read_must_be_bounded');
    $assert(str_contains($snapshot, 'public function activeFeedback(')
        && str_contains($snapshot, 'activeSnapshot($storedScope, $projectionKind, $currentAclRecheck)')
        && str_contains($snapshot, 'class_archive_collection_feedback_limit'), 'feedback_read_must_recheck_current_active_snapshot_and_fail_closed');
    $assert(str_contains($snapshot, 'A no-longer-visible target is intentionally inert')
        && str_contains($snapshot, 'not reveal whether it was removed'), 'stale_feedback_must_not_become_an_acl_oracle');
    $assert(str_contains($gateway, "\$snapshot['preferences'] = ['hidden' => \$hiddenItems];")
        && str_contains($gateway, "(\$feedback['feedback'] ?? null) === CollectionSnapshotService::FEEDBACK_HIDE"), 'home_hide_must_be_server_filtered_before_serialization');
    $assert(str_contains($gateway, "\$pins['items'] = array_values(array_filter(")
        && str_contains($gateway, 'hiddenTargets'), 'hidden_items_must_not_reappear_through_private_pin_projection');
    $assert(str_contains($controller, "'collections/feedback/set' => [")
        && str_contains($controller, "'collections/feedback/clear' => [")
        && str_contains($controller, 'self::requireMutationToken($body)'), 'feedback_mutations_must_retain_the_existing_internal_csrf_boundary');
    foreach ([
        "['/api/class-archive/collections/feedback/set', '/api/collections/feedback/set']",
        "['/api/class-archive/collections/feedback/clear', '/api/collections/feedback/clear']",
    ] as $route) {
        $assert(str_contains($bff, $route), 'feedback_bff_allowlist_missing');
    }
    $assert(str_contains($ui, 'function hiddenCollectionPreferences(hidden, onHomeChanged)')
        && str_contains($ui, "'/api/class-archive/collections/feedback/clear'"), 'ui_must_offer_an_explicit_restore_path_for_hidden_cards');
    $assert(!str_contains($controller, "'memories/save'")
        && !str_contains($controller, "'manage/auto-collections/cover'")
        && !str_contains($bff, "'/api/class-archive/memories/save'"), 'unavailable_memory_album_or_auto_collection_cover_workflows_must_not_be_faked');
    fwrite(STDOUT, "COLLECTION_FEEDBACK_PROJECTION=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'COLLECTION_FEEDBACK_PROJECTION=FAIL reason=' . $error->getMessage() . "\n");
    exit(1);
}
