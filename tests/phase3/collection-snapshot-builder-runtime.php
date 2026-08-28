<?php

declare(strict_types=1);

function collectionBuilderFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_BUILDER_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_BUILDER_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_BUILDER_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/CollectionSnapshotService.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/plugins/ClassIdentity/src/Gateway/CollectionSnapshotBuilder.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

/** @param array<string,mixed> $value */
function collectionBuilderPayloadSafe(array $value): bool
{
    foreach ($value as $key => $entry) {
        if (!is_string($key)) {
            return false;
        }
        $lower = strtolower($key);
        foreach (['piwigo', 'immich', 'principal', 'account', 'seat', 'identity', 'personid', 'storage', 'path', 'filename', 'token', 'secret', 'checksum', 'embedding', 'owner'] as $forbidden) {
            if (str_contains($lower, $forbidden)) {
                return false;
            }
        }
        if (is_array($entry) && !array_is_list($entry) && !collectionBuilderPayloadSafe($entry)) {
            return false;
        }
    }
    return true;
}

/** @param list<array<string,mixed>> $items */
function collectionBuilderItem(array $items, string $kind, string $key): ?array
{
    foreach ($items as $item) {
        if (is_array($item) && ($item['itemKind'] ?? null) === $kind && ($item['itemKey'] ?? null) === $key) {
            return $item;
        }
    }
    return null;
}

$run = strtolower(bin2hex(random_bytes(6)));
// Keep the longest v17 table name under MariaDB's 64-byte identifier limit.
$basePrefix = 'ci_cb_' . $run . '_';
$schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
$snapshots = new \ClassIdentity\CollectionSnapshotService($repository);
$assertions = 0;
$exit = 0;
$createdNative = [];

try {
    $photo = $basePrefix . 'class_identity_photo';
    $principal = $basePrefix . 'class_identity_principal';
    foreach ([$photo, $principal] as $table) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1) {
            collectionBuilderFail('collection_builder_fixture_table_invalid');
        }
    }
    if ($db->query('CREATE TABLE `' . $photo . '` (`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false
        || $db->query('CREATE TABLE `' . $principal . '` (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci') === false) {
        collectionBuilderFail('collection_builder_fixture_create_failed');
    }
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection'))->invoke($schema);
    $createdNative = classIdentityCreateNativeProjectionFixture($db, (string) $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots'))->invoke($schema);

    $heritageId = '10000000-0000-4000-8000-000000000001';
    $livingId = '10000000-0000-4000-8000-000000000002';
    $personId = '20000000-0000-4000-8000-000000000001';
    $albumId = '30000000-0000-4000-8000-000000000001';
    $spotlightId = '40000000-0000-4000-8000-000000000001';
    if ($db->query("INSERT INTO `{$photo}` (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')), (UNHEX('10000000000040008000000000000002'))") === false
        || $db->query("INSERT INTO `{$principal}` (`id`) VALUES (1)") === false) {
        collectionBuilderFail('collection_builder_fixture_rows_failed');
    }

    $heritage = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '合成历史照片', '2023-10-18', ['合成相册'], '历史 合成', 930001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101],
    );
    $living = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $livingId, 'LIVING', 'ACTIVE', 'ACTIVE', '合成动态照片', null, ['合成相册'], '动态 合成', 930002, 'UNKNOWN', 'UNKNOWN', null, [101],
    );
    $catalog = $store->rebuildPhotos([$heritage, $living], false, $store->beginPhotoCatalogBuild());
    if (($catalog['changed'] ?? null) !== true || ($catalog['count'] ?? null) !== 2) {
        collectionBuilderFail('collection_builder_catalog_failed');
    }
    ++$assertions;

    $heritagePhoto = $heritage->publicProjection();
    $livingPhoto = $living->publicProjection();
    $payloads = [
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 2, 'groups' => [[
                'key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH', 'total' => 1, 'items' => [$heritagePhoto],
            ], [
                'key' => 'unknown', 'label' => '日期未知', 'kind' => 'UNKNOWN', 'total' => 1, 'items' => [$livingPhoto],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 1, 'items' => [[
                'id' => $albumId, 'name' => '合成相册', 'displayAlias' => '合成班级相册', 'total' => 2,
                'coverPhotoId' => $heritageId, 'photo_ids' => [$heritageId, $livingId],
                'sourceLabel' => '合成来源', 'eventLabel' => '合成活动', 'dateLabel' => '班级历史',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 1, 'items' => [[
                'id' => $personId, 'label' => '测试人物', 'photo_count' => 2,
                'cover_photo_id' => $livingId, 'photo_ids' => [$heritageId, $livingId],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 1, 'items' => [[
                'label' => '合成回忆', 'kind' => 'EVENT', 'photo_count' => 2,
                'cover_photo_id' => $heritageId, 'photo_ids' => [$heritageId, $livingId], 'source_reason' => 'MEMORY:full-fixture',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => true, 'total' => 1, 'item' => [
                'id' => $spotlightId, 'albumId' => $albumId, 'albumName' => '合成班级相册',
                'coverPhotoId' => $livingId, 'description' => '合成精选说明', 'publisherLabel' => '合成成员', 'expiresAt' => '2099-01-01 00:00:00.000000',
            ]],
        ],
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 1, 'groups' => [[
                'key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH', 'total' => 1, 'items' => [$heritagePhoto],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 1, 'items' => [[
                'id' => $albumId, 'name' => '合成相册', 'displayAlias' => '合成班级相册', 'total' => 1,
                'coverPhotoId' => $heritageId, 'photo_ids' => [$heritageId],
                'sourceLabel' => '合成来源', 'eventLabel' => '合成活动', 'dateLabel' => '班级历史',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 1, 'items' => [[
                'id' => $personId, 'label' => '测试人物', 'photo_count' => 1,
                'cover_photo_id' => $heritageId, 'photo_ids' => [$heritageId],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 1, 'items' => [[
                'label' => '合成回忆', 'kind' => 'EVENT', 'photo_count' => 1,
                'cover_photo_id' => $heritageId, 'photo_ids' => [$heritageId], 'source_reason' => 'MEMORY:heritage-fixture',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
    ];
    $kinds = [
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
    ];
    $aggregate = $store->rebuildAggregates($payloads, $kinds, $store->beginAggregateBuild($kinds));
    if (($aggregate['changed'] ?? null) !== true || count((array) ($aggregate['changed_kinds'] ?? [])) !== 5) {
        collectionBuilderFail('collection_builder_aggregate_failed');
    }
    ++$assertions;

    $dry = \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots, true);
    if (($dry['result'] ?? null) !== 'PASS' || ($dry['dryRun'] ?? null) !== true
        || (array) ($snapshots->state('FULL')['items'] ?? []) !== []) {
        collectionBuilderFail('collection_builder_dry_run_mutated_state');
    }
    ++$assertions;

    $built = \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots);
    if (($built['result'] ?? null) !== 'PASS' || count((array) ($built['scopes'] ?? [])) !== 2) {
        collectionBuilderFail('collection_builder_publish_failed');
    }
    ++$assertions;

    $visible = static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $item->publicProjection($item->photoIds());
    $fullHome = $snapshots->activeSnapshot('FULL', 'HOME', $visible);
    $heritageHome = $snapshots->activeSnapshot('HERITAGE_ONLY', 'HOME', $visible);
    $fullPerson = collectionBuilderItem((array) ($fullHome['items'] ?? []), 'PERSON', $personId);
    $heritagePerson = collectionBuilderItem((array) ($heritageHome['items'] ?? []), 'PERSON', $personId);
    $fullSpotlight = collectionBuilderItem((array) ($fullHome['items'] ?? []), 'SPOTLIGHT', $spotlightId);
    $heritageSpotlight = collectionBuilderItem((array) ($heritageHome['items'] ?? []), 'SPOTLIGHT', $spotlightId);
    $fullRecommendation = null;
    foreach ((array) ($fullHome['items'] ?? []) as $item) {
        if (is_array($item) && (($item['payload']['section'] ?? null) === 'RECOMMENDATION')) {
            $fullRecommendation = $item;
            break;
        }
    }
    if (($fullHome['revision'] ?? null) !== $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL)
        || ($heritageHome['revision'] ?? null) !== $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE)
        || ($fullPerson['photoCount'] ?? null) !== 2 || ($heritagePerson['photoCount'] ?? null) !== 1
        || ($fullSpotlight['coverPhotoId'] ?? null) !== $livingId || $heritageSpotlight !== null
        || !is_array($fullRecommendation)
        || !is_array($fullRecommendation['payload'] ?? null)
        || (($fullRecommendation['payload']['title'] ?? null) !== '值得再看')
        || (($fullRecommendation['payload']['badge'] ?? null) !== '值得再看')
        || (($fullPerson['itemKey'] ?? null) !== $personId)
        || (array_key_exists('personId', (array) ($fullPerson['payload'] ?? [])))) {
        collectionBuilderFail('collection_builder_scope_acl_projection_failed');
    }
    ++$assertions;

    foreach ([$fullHome, $heritageHome] as $snapshot) {
        foreach ((array) ($snapshot['items'] ?? []) as $item) {
            if (!is_array($item) || !collectionBuilderPayloadSafe((array) ($item['payload'] ?? []))) {
                collectionBuilderFail('collection_builder_sensitive_payload_exposed');
            }
        }
    }
    $suggestions = $snapshots->activeSnapshot('FULL', 'SEARCH_SUGGESTION', $visible);
    foreach ((array) ($suggestions['items'] ?? []) as $item) {
        if (!is_array($item) || ($item['photoIds'] ?? null) !== [] || ($item['coverPhotoId'] ?? null) !== null) {
            collectionBuilderFail('collection_builder_search_suggestion_media_leak');
        }
    }
    ++$assertions;

    // A write-side aggregate change deliberately advances the presentation
    // epoch before the next snapshot rebuild.  The active bundle remains the
    // last complete one; this is the only retained fallback that Gateway may
    // read.  The test proves that its items still need a fresh per-photo ACL
    // callback: the Family view must not retain the living id/count/cover
    // that existed in the FULL snapshot just because the pointer is older.
    $stalePayloads = $payloads;
    $stalePayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]['items'][0]['eventLabel'] = '调整后的合成活动';
    $stalePayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE][\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]['items'][0]['eventLabel'] = '调整后的合成活动';
    $staleAggregate = $store->rebuildAggregates(
        $stalePayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS],
        $store->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]),
    );
    $staleState = $snapshots->state('HERITAGE_ONLY');
    $heritageFallback = $snapshots->activeSnapshot('HERITAGE_ONLY', 'HOME', static function (\ClassIdentity\CollectionSnapshotItem $item) use ($heritageId): ?array {
        return $item->publicProjection(array_values(array_filter(
            $item->photoIds(),
            static fn(string $photoId): bool => hash_equals($photoId, $heritageId),
        )));
    });
    $fallbackPerson = collectionBuilderItem((array) ($heritageFallback['items'] ?? []), 'PERSON', $personId);
    $fallbackRevisions = array_values(array_unique(array_map(
        static fn(array $item): string => (string) ($item['revision'] ?? ''),
        (array) ($staleState['items'] ?? []),
    )));
    if (($staleAggregate['changed'] ?? null) !== true
        || hash_equals($previousHeritageRevision = (string) ($heritageHome['revision'] ?? ''), $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE))
        || ($heritageFallback['revision'] ?? null) !== $previousHeritageRevision
        || $fallbackRevisions !== [$previousHeritageRevision]
        || (($fallbackPerson['photoCount'] ?? null) !== 1)
        || (($fallbackPerson['coverPhotoId'] ?? null) !== $heritageId)
        || str_contains(json_encode($heritageFallback, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $livingId)) {
        collectionBuilderFail('collection_builder_retained_snapshot_acl_fallback_failed');
    }
    ++$assertions;

    // The current Gateway aggregate is still a legacy singleton in this
    // branch, so this is intentionally a pure builder contract rather than a
    // claim that live multiple-Spotlight source projection has shipped. The
    // cyclic order has no clock or GET-side state: equal revisions repeat and
    // adjacent hexadecimal revisions rotate A -> B -> C exactly once each.
    $spotlightIdB = '40000000-0000-4000-8000-000000000002';
    $spotlightIdC = '40000000-0000-4000-8000-000000000003';
    $multiPayloads = $payloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL];
    $multiPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT] = [
        'active' => true,
        'total' => 3,
        'items' => [
            ['id' => $spotlightIdC, 'albumId' => $albumId, 'albumName' => '精选 C', 'coverPhotoId' => $heritageId, 'description' => 'C'],
            ['id' => $spotlightId, 'albumId' => $albumId, 'albumName' => '精选 A', 'coverPhotoId' => $heritageId, 'description' => 'A'],
            ['id' => $spotlightIdB, 'albumId' => $albumId, 'albumName' => '精选 B', 'coverPhotoId' => $livingId, 'description' => 'B'],
        ],
    ];
    $rounds = [];
    foreach ([str_repeat('0', 64), str_repeat('0', 63) . '1', str_repeat('0', 63) . '2'] as $revision) {
        $round = \ClassIdentity\Gateway\CollectionSnapshotBuilder::buildItemsForPayloads($multiPayloads, $revision);
        $rounds[] = array_map(static fn(array $item): string => (string) $item['itemKey'], $round[\ClassIdentity\CollectionSnapshotService::KIND_SPOTLIGHT]);
    }
    if ($rounds !== [
        [$spotlightId, $spotlightIdB, $spotlightIdC],
        [$spotlightIdB, $spotlightIdC, $spotlightId],
        [$spotlightIdC, $spotlightId, $spotlightIdB],
    ]) {
        collectionBuilderFail('collection_builder_multi_spotlight_round_robin_failed');
    }
    ++$assertions;

    $firstSnapshotId = (string) ($fullHome['snapshotId'] ?? '');
    $refreshedAfterFallback = \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots);
    if (($refreshedAfterFallback['result'] ?? null) !== 'PASS') {
        collectionBuilderFail('collection_builder_fallback_republish_failed');
    }
    $again = \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots);
    foreach ((array) ($again['scopes'] ?? []) as $scope) {
        if (!is_array($scope) || ($scope['skipped'] ?? null) !== 'CURRENT') {
            collectionBuilderFail('collection_builder_idempotence_failed');
        }
    }
    ++$assertions;

    $store->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS], 'TEST_COLLECTION_SNAPSHOT_EPOCH', false);
    try {
        \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots);
        collectionBuilderFail('collection_builder_stale_aggregate_accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') {
            throw $error;
        }
    }
    ++$assertions;
    $repair = $store->rebuildAggregates($payloads, [\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS], $store->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]));
    if (($repair['changed'] ?? null) !== true) {
        collectionBuilderFail('collection_builder_aggregate_repair_failed');
    }
    $rebuilt = \ClassIdentity\Gateway\CollectionSnapshotBuilder::rebuildWith($store, $snapshots);
    $fullHomeAfter = $snapshots->activeSnapshot('FULL', 'HOME', $visible);
    if (($rebuilt['result'] ?? null) !== 'PASS' || !is_string($fullHomeAfter['snapshotId'] ?? null)
        || hash_equals($firstSnapshotId, (string) $fullHomeAfter['snapshotId'])
        || ($fullHomeAfter['revision'] ?? null) !== $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL)) {
        collectionBuilderFail('collection_builder_epoch_rotation_failed');
    }
    ++$assertions;

    fwrite(STDOUT, 'COLLECTION_SNAPSHOT_BUILDER_RUNTIME=PASS assertions=' . $assertions . ' run=' . $run . "\n");
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'COLLECTION_SNAPSHOT_BUILDER_RUNTIME=FAIL run=' . $run . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'collection_feedback', 'collection_pin', 'collection_maintenance_state', 'collection_snapshot_item',
        'collection_snapshot_pointer', 'collection_snapshot', 'read_photo', 'read_projection', 'native_source_epoch',
        'photo', 'principal',
    ] as $suffix) {
        $table = $basePrefix . 'class_identity_' . $suffix;
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) === 1) {
            $db->query('DROP TABLE IF EXISTS `' . $table . '`');
        }
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'COLLECTION_SNAPSHOT_BUILDER_RUNTIME_CLEANUP=FAIL reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n");
            $exit = 1;
        }
    }
    $like = $db->real_escape_string($basePrefix . 'class_identity_') . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $remainingCount = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'COLLECTION_SNAPSHOT_BUILDER_RUNTIME_CLEANUP=FAIL remaining=' . $remainingCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
