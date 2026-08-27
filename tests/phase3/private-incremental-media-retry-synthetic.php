<?php

declare(strict_types=1);

/**
 * Public-safe synthetic fault/retry proof for the explicit incremental media
 * operator.  It exercises the same set and aggregate invariants asserted by
 * private-full-incremental-media.ps1 without opening Docker, localhost, a
 * private database, or any source/managed media.
 */

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $code) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $code;
    }
};

/**
 * @param list<string> $deltaMarkers
 * @param list<string> $pendingMarkers
 */
function incrementalRetryProof(
    int $baseline,
    int $delta,
    int $profiles,
    array $deltaMarkers,
    array $pendingMarkers,
    int $fullCached,
    int $fullMissing,
    int $queueCached,
    int $queueMissing,
): bool {
    if ($baseline < 1 || $delta < 0 || $profiles < 1
        || count($deltaMarkers) !== $delta
        || count(array_unique($deltaMarkers, SORT_STRING)) !== $delta
        || count(array_unique($pendingMarkers, SORT_STRING)) !== count($pendingMarkers)) {
        return false;
    }
    $allowed = array_fill_keys($deltaMarkers, true);
    foreach ($pendingMarkers as $marker) {
        if (!isset($allowed[$marker])) {
            return false;
        }
    }
    $pending = count($pendingMarkers);
    $completed = $delta - $pending;
    if ($completed < 0) {
        return false;
    }
    return $fullCached - $queueCached === ($baseline + $completed) * $profiles
        && $fullMissing === $queueMissing
        && $queueCached + $queueMissing === $pending * $profiles
        && $fullCached + $fullMissing === ($baseline + $delta) * $profiles;
}

$delta = [
    '10000000-0000-4000-8000-000000000001:101',
    '10000000-0000-4000-8000-000000000002:102',
    '10000000-0000-4000-8000-000000000003:103',
];
$baseline = 5;
$profiles = 6;

// A repeated operator run is a proven no-op: no queue entry, no generation,
// and all baseline derivatives already cached.
$assert(incrementalRetryProof($baseline, 0, $profiles, [], [], 30, 0, 0, 0), 'verified_noop_rejected');

// Fresh apply: all three markers remain. Baseline is fully cached; the delta
// may be entirely missing and is the only generation set.
$assert(incrementalRetryProof($baseline, 3, $profiles, $delta, $delta, 30, 18, 0, 18), 'fresh_delta_rejected');

// Simulated crash after one marker was fully generated and consumed. A retry
// accepts the exact remaining subset and proves the absent marker is cached.
$remaining = [$delta[1], $delta[2]];
$assert(incrementalRetryProof($baseline, 3, $profiles, $delta, $remaining, 36, 12, 0, 12), 'partial_retry_rejected');

// Simulated crash after all derivatives but before the atomic Class AI commit.
// No marker remains, yet the whole catalog is proven fully cached.
$assert(incrementalRetryProof($baseline, 3, $profiles, $delta, [], 48, 0, 0, 0), 'post_derivative_retry_rejected');

// Any unrelated marker, duplicate, missing baseline derivative, or hidden
// generation outside the queue must fail closed.
$assert(!incrementalRetryProof($baseline, 3, $profiles, $delta, [...$remaining, '20000000-0000-4000-8000-000000000001:999'], 36, 12, 0, 12), 'foreign_marker_allowed');
$assert(!incrementalRetryProof($baseline, 3, $profiles, $delta, [$delta[1], $delta[1]], 36, 12, 0, 12), 'duplicate_marker_allowed');
$assert(!incrementalRetryProof($baseline, 3, $profiles, $delta, $remaining, 35, 13, 0, 12), 'baseline_cache_gap_allowed');
$assert(!incrementalRetryProof($baseline, 3, $profiles, $delta, $remaining, 36, 12, 0, 11), 'unaccounted_generation_allowed');
$assert(!incrementalRetryProof($baseline, 3, $profiles, [$delta[0], $delta[1]], $remaining, 36, 12, 0, 12), 'delta_marker_count_drift_allowed');

$aggregate = json_encode([
    'total' => 8,
    'baseline' => 5,
    'delta' => 3,
    'old_selected' => 0,
    'private_artifacts' => 'OWNER_ONLY_IGNORED',
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
$assert(!str_contains($aggregate, 'M:') && !str_contains($aggregate, 'media_reference')
    && !str_contains($aggregate, 'originalPath') && !str_contains($aggregate, 'filename'), 'aggregate_leaks_private_location');

if ($failures !== []) {
    fwrite(STDERR, 'PRIVATE_INCREMENTAL_MEDIA_RETRY=FAIL assertions=' . $assertions
        . ' failures=' . implode(';', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PRIVATE_INCREMENTAL_MEDIA_RETRY=PASS assertions={$assertions} evidence=SYNTHETIC_NO_RUNTIME\n");
