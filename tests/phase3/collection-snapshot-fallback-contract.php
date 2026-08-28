<?php

declare(strict_types=1);

/**
 * Public-safe source contract for the V4 retained-active-snapshot fallback.
 *
 * The fallback is deliberately an availability mechanism only: it may serve
 * the one coherent active bundle from before a projection rebuild, but every
 * emitted item still goes through the current point-read policy hook.  This
 * test opens no database, private runtime, media file, or network resource.
 */

$root = dirname(__DIR__, 2);
$read = static function (string $relative) use ($root): string {
    $contents = file_get_contents($root . '/' . $relative);
    if (!is_string($contents) || $contents === '') {
        throw new RuntimeException('collection_snapshot_fallback_fixture_missing');
    }
    return $contents;
};

$gateway = $read('plugins/ClassIdentity/src/Gateway/GatewayService.php');
$snapshots = $read('plugins/ClassIdentity/src/CollectionSnapshotService.php');
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};

$method = static function (string $source, string $needle): string {
    $start = strpos($source, $needle);
    if ($start === false) {
        return '';
    }
    $brace = strpos($source, '{', $start);
    if ($brace === false) {
        return '';
    }
    $depth = 0;
    for ($i = $brace, $length = strlen($source); $i < $length; ++$i) {
        if ($source[$i] === '{') {
            ++$depth;
        } elseif ($source[$i] === '}' && --$depth === 0) {
            return substr($source, $brace, $i - $brace + 1);
        }
    }
    return '';
};

try {
    $bundle = $method($gateway, 'private function publishedCollectionSnapshotBundle(');
    $home = $method($gateway, 'public function collectionsHome(): array');
    $pins = $method($gateway, 'public function collectionPins(): array');
    $suggestions = $method($gateway, 'private function persistentSearchSuggestions(');
    $current = $method($gateway, 'private function requireCurrentCollectionSnapshot(');

    $assert($bundle !== ''
        && str_contains($gateway, 'private function publishedCollectionSnapshotBundle(string $scope, string $epoch, bool $allowFallback): array')
        && str_contains($bundle, 'CollectionSnapshotService::KIND_HOME')
        && str_contains($bundle, 'CollectionSnapshotService::KIND_MEMORY')
        && str_contains($bundle, 'CollectionSnapshotService::KIND_SPOTLIGHT')
        && str_contains($bundle, 'CollectionSnapshotService::KIND_SEARCH_SUGGESTION'),
        'fallback_requires_the_closed_four_kind_bundle');
    $assert($bundle !== ''
        && str_contains($bundle, '($item[\'state\'] ?? null) !== \'ACTIVE\'')
        && str_contains($bundle, 'hash_equals($snapshotRevision')
        && str_contains($bundle, "'mode' => 'CURRENT'")
        && str_contains($bundle, "'mode' => 'FALLBACK'"),
        'fallback_must_accept_only_one_coherent_active_bundle');
    $assert($bundle !== ''
        && str_contains($bundle, "throw new \\RuntimeException('class_archive_collection_snapshot_stale')")
        && !str_contains($bundle, 'CollectionSnapshotBuilder::rebuild')
        && !str_contains($bundle, 'visiblePhotos('),
        'fallback_must_fail_closed_without_a_read_time_rebuild_or_library_scan');
    $assert($home !== ''
        && str_contains($home, 'publishedCollectionSnapshotBundle($scope, $before, true)')
        && str_contains($home, 'recheckCollectionSnapshotItem')
        && str_contains($home, '$snapshot[\'snapshotState\'] = $bundle[\'mode\'];'),
        'home_must_recheck_current_policy_and_declare_fallback_state');
    $assert($pins !== ''
        && str_contains($pins, 'publishedCollectionSnapshotBundle($scope, $epoch, true)')
        && str_contains($pins, 'recheckCollectionSnapshotItem'),
        'read_only_pins_may_use_the_same_acl_rechecked_fallback');
    $assert($suggestions !== ''
        && str_contains($suggestions, 'publishedCollectionSnapshotBundle($scope, $before, true)')
        && str_contains($suggestions, 'recheckSearchSuggestionSnapshotItem')
        && !str_contains($suggestions, '$this->visiblePhotos()'),
        'suggestions_may_fall_back_only_to_the_rechecked_persisted_snapshot');
    $assert($current !== ''
        && !str_contains($current, 'publishedCollectionSnapshotBundle($scope, $epoch, true)')
        && str_contains($current, 'hash_equals($epoch, $item[\'revision\'])'),
        'mutations_remain_strictly_current_and_cannot_use_the_fallback');
    $assert(str_contains($snapshots, 'public function activeSnapshot(')
        && str_contains($snapshots, '$currentAclRecheck($item)')
        && str_contains($snapshots, '$cover = $visible[0] ?? null;')
        && str_contains($snapshots, "'photoCount' => count(\$visible)"),
        'snapshot_domain_rechecks_each_visible_photo_before_counts_or_covers');

    if ($failures !== []) {
        throw new RuntimeException(implode(';', $failures));
    }
    fwrite(STDOUT, "COLLECTION_SNAPSHOT_FALLBACK_CONTRACT=PASS assertions={$assertions}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'COLLECTION_SNAPSHOT_FALLBACK_CONTRACT=FAIL assertions=' . $assertions . ' reason='
        . preg_replace('/[^A-Za-z0-9_.;-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
