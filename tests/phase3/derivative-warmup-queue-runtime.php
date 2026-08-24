<?php

declare(strict_types=1);

function warmQueueFail(string $code): never
{
    fwrite(STDERR, 'DERIVATIVE_WARMUP_QUEUE_RUNTIME=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) . "\n");
    exit(1);
}

function warmQueueFilesystemFixture(): never
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'class-archive-derivative-queue-' . bin2hex(random_bytes(8));
    $assertions = 0;
    $cleanup = static function (string $path) use (&$cleanup): void {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = scandir($path);
        if (is_array($children)) {
            foreach ($children as $child) {
                if ($child !== '.' && $child !== '..') {
                    $cleanup($path . DIRECTORY_SEPARATOR . $child);
                }
            }
        }
        @rmdir($path);
    };

    try {
        if (!mkdir($root . DIRECTORY_SEPARATOR . '_data', 0770, true)) {
            throw new RuntimeException('filesystem_root_create_failed');
        }
        define('PHPWG_ROOT_PATH', $root . DIRECTORY_SEPARATOR);
        require dirname(__DIR__, 2) . '/plugins/ClassArchivePolicy/src/DerivativeWarmupQueue.php';

        $entry = [
            'class_photo_id' => '12345678-1234-4123-8123-123456789abc',
            'piwigo_image_id' => 424242,
        ];
        ClassArchiveDerivativeWarmupQueue::pending();
        $queue = $root . '/_data/class-archive/derivative-warmup';
        $quarantine = $root . '/_data/class-archive/derivative-warmup-quarantine';

        $validToken = bin2hex(random_bytes(12));
        $validTemp = $queue . '/.pending-' . $validToken;
        $validPayload = json_encode([
            'version' => 1,
            'class_photo_id' => $entry['class_photo_id'],
            'piwigo_image_id' => $entry['piwigo_image_id'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($validTemp, $validPayload, LOCK_EX) !== strlen($validPayload)
            || !chmod($validTemp, 0660)) {
            throw new RuntimeException('filesystem_valid_temp_create_failed');
        }
        $pending = ClassArchiveDerivativeWarmupQueue::pending();
        ++$assertions;
        if ($pending !== [$entry] || file_exists($validTemp) || is_link($validTemp)) {
            throw new RuntimeException('filesystem_valid_temp_recovery_failed');
        }
        ClassArchiveDerivativeWarmupQueue::complete($entry['class_photo_id'], $entry['piwigo_image_id']);

        $partialToken = bin2hex(random_bytes(12));
        $partialTemp = $queue . '/.pending-' . $partialToken;
        $partialPayload = '{"version":1,"class_photo_id":"sigkill';
        if (file_put_contents($partialTemp, $partialPayload, LOCK_EX) !== strlen($partialPayload)
            || !chmod($partialTemp, 0600)) {
            throw new RuntimeException('filesystem_partial_temp_create_failed');
        }
        ClassArchiveDerivativeWarmupQueue::pending();
        $isolated = glob($quarantine . '/stale-temp-' . $partialToken . '-invalid-*.quarantined');
        $isolated = is_array($isolated) ? $isolated : [];
        ++$assertions;
        if (count($isolated) !== 1 || file_exists($partialTemp) || is_link($partialTemp)) {
            throw new RuntimeException('filesystem_partial_temp_isolation_failed');
        }
        $isolatedStat = lstat($isolated[0]);
        ++$assertions;
        if (!is_array($isolatedStat)
            || (($isolatedStat['mode'] ?? 0) & 0777) !== 0600
            || (int) ($isolatedStat['nlink'] ?? 0) !== 1
            || !hash_equals(hash('sha256', $partialPayload), hash_file('sha256', $isolated[0]) ?: '')) {
            throw new RuntimeException('filesystem_partial_temp_changed');
        }

        $unknown = $queue . '/arbitrary-entry';
        if (file_put_contents($unknown, "preserve\n", LOCK_EX) !== 9 || !chmod($unknown, 0660)) {
            throw new RuntimeException('filesystem_unknown_create_failed');
        }
        $denied = false;
        try {
            ClassArchiveDerivativeWarmupQueue::pending();
        } catch (RuntimeException $error) {
            $denied = $error->getMessage() === 'derivative_warmup_queue_unknown_entry';
        }
        ++$assertions;
        if (!$denied || file_get_contents($unknown) !== "preserve\n") {
            throw new RuntimeException('filesystem_unknown_not_fail_closed');
        }
        if (!unlink($unknown)) {
            throw new RuntimeException('filesystem_unknown_cleanup_failed');
        }

        $lock = $root . '/_data/class-archive/derivative-warmup.lock';
        $lockStat = lstat($lock);
        ++$assertions;
        if (!is_array($lockStat)
            || (($lockStat['mode'] ?? 0) & 0777) !== 0660
            || (int) ($lockStat['nlink'] ?? 0) !== 1
            || (int) ($lockStat['size'] ?? -1) !== 0) {
            throw new RuntimeException('filesystem_queue_lock_invalid');
        }

        $cleanup($root);
        ++$assertions;
        if (file_exists($root) || is_link($root)) {
            throw new RuntimeException('filesystem_fixture_cleanup_failed');
        }
        fwrite(STDOUT, 'DERIVATIVE_WARMUP_QUEUE_FILESYSTEM=PASS assertions=' . $assertions . "\n");
        exit(0);
    } catch (Throwable $error) {
        $cleanup($root);
        warmQueueFail('filesystem_' . $error->getMessage());
    }
}

if (getenv('CLASS_ARCHIVE_ALLOW_DERIVATIVE_QUEUE_FIXTURE') !== '1'
    || PHP_SAPI !== 'cli'
    || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
    warmQueueFail('runtime_forbidden');
}

if (getenv('CLASS_ARCHIVE_DERIVATIVE_QUEUE_FILESYSTEM_FIXTURE') === '1') {
    warmQueueFilesystemFixture();
}

$queued = null;
$completed = false;
$assertions = 0;
$fixturePaths = [];
$quarantineFixturePaths = [];
$metadataFixture = null;
try {
    chdir('/var/www/html/piwigo') || throw new RuntimeException('root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once '/workspace/infra/scripts/warm-photo-cache.php';
    require_once PHPWG_ROOT_PATH . 'include/derivative.inc.php';

    if (!class_exists('ClassArchiveDerivativeWarmupQueue', false)) {
        throw new RuntimeException('queue_class_unavailable');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $rows = $repository->fetchAll(
        'SELECT HEX(pm.`class_photo_id`) AS `class_photo_id_hex`,pm.`piwigo_image_id`,'
        . 'i.`width`,i.`height`,i.`rotation` FROM `' . $repository->table('photo') . '` pm '
        . 'JOIN `' . $prefixeTable . 'images` i ON i.`id`=pm.`piwigo_image_id` '
        . 'WHERE pm.`state`=\'ACTIVE\' AND pm.`piwigo_image_id` IS NOT NULL '
        . 'AND i.`width`>0 AND i.`height`>0 AND i.`rotation` IS NOT NULL '
        . 'ORDER BY pm.`piwigo_image_id` ASC LIMIT 100',
    );
    $before = ClassArchiveDerivativeWarmupQueue::pending();
    $already = [];
    foreach ($before as $entry) {
        $already[$entry['class_photo_id'] . ':' . $entry['piwigo_image_id']] = true;
    }
    foreach ($rows as $row) {
        $candidate = [
            'class_photo_id' => \ClassIdentity\ClassArchivePhoto::binaryToId(
                hex2bin((string) ($row['class_photo_id_hex'] ?? '')) ?: '',
            ),
            'piwigo_image_id' => (int) ($row['piwigo_image_id'] ?? 0),
        ];
        if (!isset($already[$candidate['class_photo_id'] . ':' . $candidate['piwigo_image_id']])) {
            $queued = $candidate;
            $metadataFixture = [
                'piwigo_image_id' => $candidate['piwigo_image_id'],
                'width' => (int) ($row['width'] ?? 0),
                'height' => (int) ($row['height'] ?? 0),
                'rotation' => (int) ($row['rotation'] ?? -1),
            ];
            break;
        }
    }
    if ($queued === null) {
        throw new RuntimeException('canonical_fixture_unavailable');
    }

    $queueDirectory = '/var/www/html/piwigo/_data/class-archive/derivative-warmup';
    $quarantineDirectory = '/var/www/html/piwigo/_data/class-archive/derivative-warmup-quarantine';
    if (!is_dir($queueDirectory)) {
        throw new RuntimeException('queue_directory_unavailable');
    }

    // Simulate SIGKILL after a complete path-free temp was flushed but before
    // its atomic rename. pending() must recover the canonical marker while
    // holding the queue lock; the random temp name is never an authorization
    // or path source.
    $recoveryToken = bin2hex(random_bytes(12));
    $recoveryTemp = $queueDirectory . '/.pending-' . $recoveryToken;
    $fixturePaths[] = $recoveryTemp;
    $recoveryPayload = json_encode([
        'version' => 1,
        'class_photo_id' => $queued['class_photo_id'],
        'piwigo_image_id' => $queued['piwigo_image_id'],
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($recoveryTemp, $recoveryPayload, LOCK_EX) !== strlen($recoveryPayload)
        || !chmod($recoveryTemp, 0660)) {
        throw new RuntimeException('recovery_temp_create_failed');
    }
    $recovered = ClassArchiveDerivativeWarmupQueue::pending();
    ++$assertions;
    if (array_values(array_filter($recovered, static fn (array $entry): bool => $entry === $queued)) !== [$queued]
        || file_exists($recoveryTemp)
        || is_link($recoveryTemp)) {
        throw new RuntimeException('complete_temp_not_recovered');
    }
    ClassArchiveDerivativeWarmupQueue::complete($queued['class_photo_id'], $queued['piwigo_image_id']);
    ++$assertions;
    if (array_filter(
        ClassArchiveDerivativeWarmupQueue::pending(),
        static fn (array $entry): bool => $entry === $queued,
    ) !== []) {
        throw new RuntimeException('recovered_marker_cleanup_failed');
    }

    // A small trusted partial write is evidence. It must leave the live queue,
    // retain identical bytes in private quarantine, and never be deleted.
    $partialToken = bin2hex(random_bytes(12));
    $partialTemp = $queueDirectory . '/.pending-' . $partialToken;
    $fixturePaths[] = $partialTemp;
    $partialPayload = '{"version":1,"class_photo_id":"interrupted';
    if (file_put_contents($partialTemp, $partialPayload, LOCK_EX) !== strlen($partialPayload)
        || !chmod($partialTemp, 0600)) {
        throw new RuntimeException('partial_temp_create_failed');
    }
    ClassArchiveDerivativeWarmupQueue::pending();
    $isolated = glob($quarantineDirectory . '/stale-temp-' . $partialToken . '-invalid-*.quarantined');
    $isolated = is_array($isolated) ? $isolated : [];
    $quarantineFixturePaths = array_merge($quarantineFixturePaths, $isolated);
    ++$assertions;
    if (file_exists($partialTemp) || is_link($partialTemp) || count($isolated) !== 1) {
        throw new RuntimeException('partial_temp_not_isolated');
    }
    $isolatedStat = lstat($isolated[0]);
    ++$assertions;
    if (!is_array($isolatedStat)
        || (($isolatedStat['mode'] ?? 0) & 0777) !== 0600
        || (int) ($isolatedStat['nlink'] ?? 0) !== 1
        || !hash_equals(hash('sha256', $partialPayload), hash_file('sha256', $isolated[0]) ?: '')) {
        throw new RuntimeException('partial_quarantine_bytes_changed');
    }

    // A name outside the two explicit internal grammars is neither ignored nor
    // quarantined. pending() fails closed and preserves it for inspection.
    $unknown = $queueDirectory . '/unexpected-' . bin2hex(random_bytes(6));
    $fixturePaths[] = $unknown;
    if (file_put_contents($unknown, "fixture\n", LOCK_EX) !== 8 || !chmod($unknown, 0660)) {
        throw new RuntimeException('unknown_fixture_create_failed');
    }
    $unknownDenied = false;
    try {
        ClassArchiveDerivativeWarmupQueue::pending();
    } catch (RuntimeException $error) {
        $unknownDenied = $error->getMessage() === 'derivative_warmup_queue_unknown_entry';
    }
    ++$assertions;
    if (!$unknownDenied || !is_file($unknown)) {
        throw new RuntimeException('unknown_entry_not_fail_closed');
    }
    if (!unlink($unknown)) {
        throw new RuntimeException('unknown_fixture_cleanup_failed');
    }

    $queueLock = '/var/www/html/piwigo/_data/class-archive/derivative-warmup.lock';
    $lockStat = lstat($queueLock);
    ++$assertions;
    if (!is_array($lockStat)
        || (($lockStat['mode'] ?? 0) & 0170000) !== 0100000
        || (($lockStat['mode'] ?? 0) & 0777) !== 0660
        || (int) ($lockStat['nlink'] ?? 0) !== 1
        || (int) ($lockStat['size'] ?? -1) !== 0) {
        throw new RuntimeException('queue_lock_not_private_regular_empty');
    }

    if (!is_array($metadataFixture)
        || $repository->execute(
            'UPDATE `' . $prefixeTable . 'images` SET `width`=0,`height`=0,`rotation`=NULL WHERE `id`=?',
            [$queued['piwigo_image_id']],
        ) !== 1
    ) {
        throw new RuntimeException('metadata_fixture_invalidation_failed');
    }
    ++$assertions;
    $projectionState = $repository->fetchOne(
        'SELECT `state` FROM `' . $repository->table('read_projection') . '` WHERE `projection_key`=\'PHOTO_CATALOG\' LIMIT 1',
    );
    if (($projectionState['state'] ?? null) !== 'STALE') {
        throw new RuntimeException('metadata_fixture_did_not_stale_projection');
    }

    ClassArchiveDerivativeWarmupQueue::enqueue($queued['class_photo_id'], $queued['piwigo_image_id']);
    $afterEnqueue = ClassArchiveDerivativeWarmupQueue::pending();
    $matches = array_values(array_filter(
        $afterEnqueue,
        static fn (array $entry): bool => $entry === $queued,
    ));
    ++$assertions;
    if (count($matches) !== 1) {
        throw new RuntimeException('canonical_marker_missing');
    }

    if (!class_exists('ClassArchiveDerivativeCacheWarmer', false)) {
        throw new RuntimeException('immediate_warmer_class_unavailable');
    }
    $immediate = ClassArchiveDerivativeCacheWarmer::warm(
        $queued['class_photo_id'],
        $queued['piwigo_image_id'],
    );
    ++$assertions;
    if ((int) ($immediate['checked'] ?? 0) !== 6
        || (int) ($immediate['cached'] ?? 0) + (int) ($immediate['generated'] ?? 0) !== 6) {
        throw new RuntimeException('immediate_exact_warmup_incomplete');
    }
    $restoredMetadata = $repository->fetchOne(
        'SELECT `width`,`height`,`rotation` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
        [$queued['piwigo_image_id']],
    );
    ++$assertions;
    if ($restoredMetadata === null
        || (int) ($restoredMetadata['width'] ?? 0) !== $metadataFixture['width']
        || (int) ($restoredMetadata['height'] ?? 0) !== $metadataFixture['height']
        || (int) ($restoredMetadata['rotation'] ?? -1) !== $metadataFixture['rotation']
    ) {
        throw new RuntimeException('metadata_fixture_not_restored');
    }
    ++$assertions;
    foreach ((new \ClassIdentity\Gateway\ReadProjectionStore($repository))->status() as $projection) {
        if (($projection['state'] ?? null) !== 'ACTIVE') {
            throw new RuntimeException('metadata_normalization_projection_not_republished');
        }
    }
    ++$assertions;
    if (array_filter(
        ClassArchiveDerivativeWarmupQueue::pending(),
        static fn (array $entry): bool => $entry === $queued,
    ) !== []) {
        throw new RuntimeException('immediate_completed_marker_retained');
    }

    // Requeue the same exact mapping to prove the periodic maintenance path
    // remains a recovery consumer after immediate prewarm. Reintroduce the
    // same missing native metadata so the batch path must normalize it and
    // publish exactly one complete catalog generation after all selected rows.
    if ($repository->execute(
        'UPDATE `' . $prefixeTable . 'images` SET `width`=0,`height`=0,`rotation`=NULL WHERE `id`=?',
        [$queued['piwigo_image_id']],
    ) !== 1) {
        throw new RuntimeException('maintenance_metadata_fixture_invalidation_failed');
    }
    ClassArchiveDerivativeWarmupQueue::enqueue($queued['class_photo_id'], $queued['piwigo_image_id']);
    $profiles = array_keys(classArchivePhotoCacheCanonicalProfiles());
    $result = classArchivePhotoCacheWarm('first-screen', $profiles, false);
    ++$assertions;
    if (($result['result'] ?? null) !== 'PASS') {
        throw new RuntimeException('warmup_failed');
    }
    ++$assertions;
    if ((int) ($result['queued'] ?? -1) < 1 || (int) ($result['queue_completed'] ?? 0) < 1) {
        throw new RuntimeException('queued_mapping_not_processed');
    }
    ++$assertions;
    if ((int) ($result['metadata_normalized'] ?? 0) !== 1 || ($result['projection_rebuilt'] ?? null) !== true) {
        throw new RuntimeException('maintenance_metadata_projection_recovery_missing');
    }
    $maintenanceMetadata = $repository->fetchOne(
        'SELECT `width`,`height`,`rotation` FROM `' . $prefixeTable . 'images` WHERE `id`=? LIMIT 1',
        [$queued['piwigo_image_id']],
    );
    ++$assertions;
    if ($maintenanceMetadata === null
        || (int) ($maintenanceMetadata['width'] ?? 0) !== $metadataFixture['width']
        || (int) ($maintenanceMetadata['height'] ?? 0) !== $metadataFixture['height']
        || (int) ($maintenanceMetadata['rotation'] ?? -1) !== $metadataFixture['rotation']
    ) {
        throw new RuntimeException('maintenance_metadata_fixture_not_restored');
    }
    ++$assertions;
    foreach ((new \ClassIdentity\Gateway\ReadProjectionStore($repository))->status() as $projection) {
        if (($projection['state'] ?? null) !== 'ACTIVE') {
            throw new RuntimeException('maintenance_metadata_projection_not_republished');
        }
    }
    ++$assertions;
    if ((int) ($result['queue_retained'] ?? -1) !== 0) {
        throw new RuntimeException('queue_completion_count_invalid');
    }
    $afterWarm = ClassArchiveDerivativeWarmupQueue::pending();
    ++$assertions;
    if (array_filter($afterWarm, static fn (array $entry): bool => $entry === $queued) !== []) {
        throw new RuntimeException('completed_marker_retained');
    }
    $completed = true;

    foreach ($quarantineFixturePaths as $fixturePath) {
        if (is_file($fixturePath) && !is_link($fixturePath) && !unlink($fixturePath)) {
            throw new RuntimeException('quarantine_fixture_cleanup_failed');
        }
    }
    ++$assertions;
    if (array_filter($fixturePaths, static fn (string $path): bool => file_exists($path) || is_link($path)) !== []) {
        throw new RuntimeException('temp_fixture_cleanup_incomplete');
    }

    fwrite(STDOUT, 'DERIVATIVE_WARMUP_QUEUE_RUNTIME=PASS assertions=' . $assertions
        . ' immediate_generated=' . (int) ($immediate['generated'] ?? 0)
        . ' immediate_cached=' . (int) ($immediate['cached'] ?? 0)
        . ' generated=' . (int) ($result['generated'] ?? 0)
        . ' cached=' . (int) ($result['cached'] ?? 0) . "\n");
} catch (Throwable $error) {
    if (is_array($metadataFixture) && isset($repository, $prefixeTable)) {
        try {
            $repository->execute(
                'UPDATE `' . $prefixeTable . 'images` SET `width`=?,`height`=?,`rotation`=? WHERE `id`=?',
                [
                    $metadataFixture['width'],
                    $metadataFixture['height'],
                    $metadataFixture['rotation'],
                    $metadataFixture['piwigo_image_id'],
                ],
            );
            \ClassIdentity\Gateway\ReadProjectionBuilder::rebuild();
        } catch (Throwable) {
            // Keep the original test failure. The canonical phase-0 reset is
            // still required before a failed fixture may be rerun.
        }
    }
    foreach ($fixturePaths as $fixturePath) {
        if (file_exists($fixturePath) || is_link($fixturePath)) {
            @unlink($fixturePath);
        }
    }
    foreach ($quarantineFixturePaths as $fixturePath) {
        if (is_file($fixturePath) && !is_link($fixturePath)) {
            @unlink($fixturePath);
        }
    }
    if ($queued !== null && !$completed) {
        try {
            foreach (ClassArchiveDerivativeWarmupQueue::pending() as $entry) {
                if ($entry === $queued) {
                    ClassArchiveDerivativeWarmupQueue::complete($queued['class_photo_id'], $queued['piwigo_image_id']);
                    break;
                }
            }
        } catch (Throwable) {
            // Preserve the first failure code; the marker itself remains
            // fail-closed and is safe for the next maintenance run.
        }
    }
    warmQueueFail($error->getMessage());
}
