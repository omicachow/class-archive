<?php

declare(strict_types=1);

/**
 * Narrow V18-only runtime proof for retained Collection Snapshot fallback.
 *
 * This test deliberately uses a random ClassIdentity table prefix inside the
 * isolated V18 synthetic MariaDB runtime.  It never reads Piwigo's live
 * photo/account rows, never binds a browser identity, and drops every fixture
 * table in finally.  The V18 scope flag prevents a developer from accidentally
 * executing it in 8091/8191/8291 or any ordinary local runtime.
 */

function collectionFallbackV18Fail(string $message): never
{
    throw new RuntimeException($message);
}

function collectionFallbackV18Identifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $identifier) !== 1) {
        collectionFallbackV18Fail('collection_fallback_v18_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function collectionFallbackV18Execute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        collectionFallbackV18Fail('collection_fallback_v18_query_failed_' . $db->errno);
    }
}

/** @return array<string,mixed>|null */
function collectionFallbackV18One(mysqli $db, string $sql): ?array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        collectionFallbackV18Fail('collection_fallback_v18_query_failed_' . $db->errno);
    }
    $row = $result->fetch_assoc();
    $result->free();
    return is_array($row) ? $row : null;
}

if (PHP_SAPI !== 'cli' || getenv('CLASS_ARCHIVE_V18_SYNTHETIC_PROOF') !== '1'
    || getenv('CLASS_ARCHIVE_RUNTIME_SCOPE') !== 'SYNTHETIC_V4_MIGRATION'
    || getenv('CLASS_ARCHIVE_V18_RUNTIME_PROOF') !== '1'
    || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=FAIL reason=isolated_v18_scope_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$collectionFallbackV18Root = dirname(__DIR__, 2);
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Access.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Schema.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Repository.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/CollectionSnapshotService.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $collectionFallbackV18Root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';
require $collectionFallbackV18Root . '/tests/support/class-identity-native-projection-fixture.php';

final class CollectionFallbackV18IdentityAdapter implements \ClassIdentity\Gateway\IdentityAdapter
{
    public function __construct(private readonly \ClassIdentity\Gateway\GatewayPrincipal $principal)
    {
    }

    public function currentPrincipal(): ?\ClassIdentity\Gateway\GatewayPrincipal
    {
        return $this->principal;
    }
}

/**
 * Point lookup is intentional: a retained bundle must not fall back to a
 * full-library scan while rechecking each candidate's current ACL.
 */
final class CollectionFallbackV18PiwigoAdapter implements \ClassIdentity\Gateway\PointPiwigoAdapter
{
    /** @var array<string,\ClassIdentity\Gateway\GatewayPhotoCandidate> */
    private array $candidates;

    public int $pointCalls = 0;
    public int $fullScanCalls = 0;

    /** @param list<\ClassIdentity\Gateway\GatewayPhotoCandidate> $candidates */
    public function __construct(array $candidates)
    {
        $this->candidates = [];
        foreach ($candidates as $candidate) {
            $this->candidates[$candidate->id()] = $candidate;
        }
    }

    public function photoCandidates(): array
    {
        ++$this->fullScanCalls;
        throw new RuntimeException('collection_fallback_v18_full_scan_forbidden');
    }

    public function photoCandidate(string $classPhotoId): ?\ClassIdentity\Gateway\GatewayPhotoCandidate
    {
        ++$this->pointCalls;
        return $this->candidates[$classPhotoId] ?? null;
    }
}

final class CollectionFallbackV18ImmichAdapter implements \ClassIdentity\Gateway\ImmichAdapter
{
    public function availability(): string
    {
        return 'UNAVAILABLE';
    }

    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($visibleClassPhotoIds, $query);
        return [];
    }
}

$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$run = strtolower(bin2hex(random_bytes(6)));
// The longest V18 snapshot-pointer table name must remain below MariaDB's
// 64-byte identifier limit.
$basePrefix = 'ci_cf_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$assertions = 0;
$exit = 0;
$createdNative = [];
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        collectionFallbackV18Fail($message);
    }
};

try {
    $v18Table = $prefixeTable . 'class_identity_spotlight_rotation_state';
    $v18Exists = collectionFallbackV18One(
        $db,
        "SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()"
            . " AND TABLE_NAME='" . $db->real_escape_string($v18Table) . "'",
    );
    $assert((int) ($v18Exists['count'] ?? 0) === 1, 'v18_schema_not_active');
    $assert(\ClassIdentity\Schema::CURRENT_VERSION === 18, 'v18_source_not_loaded');

    $photoTable = $ci . 'photo';
    $principalTable = $ci . 'principal';
    foreach ([$photoTable, $principalTable] as $table) {
        collectionFallbackV18Identifier($table);
    }
    collectionFallbackV18Execute($db, 'CREATE TABLE ' . collectionFallbackV18Identifier($photoTable) . ' ('
        . '`class_photo_id` BINARY(16) NOT NULL,PRIMARY KEY (`class_photo_id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    collectionFallbackV18Execute($db, 'CREATE TABLE ' . collectionFallbackV18Identifier($principalTable) . ' ('
        . '`id` BIGINT UNSIGNED NOT NULL,PRIMARY KEY (`id`)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection'))->invoke($schema);
    $createdNative = classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationPhotosAppV4CollectionSnapshots'))->invoke($schema);
    $repository = new \ClassIdentity\Repository($db, $basePrefix);
    $store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
    $snapshots = new \ClassIdentity\CollectionSnapshotService($repository);

    $heritageId = '10000000-0000-4000-8000-000000000001';
    $livingId = '10000000-0000-4000-8000-000000000002';
    $personId = '20000000-0000-4000-8000-000000000001';
    $albumId = '30000000-0000-4000-8000-000000000001';
    collectionFallbackV18Execute($db, 'INSERT INTO ' . collectionFallbackV18Identifier($photoTable)
        . " (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')),(UNHEX('10000000000040008000000000000002'))");
    collectionFallbackV18Execute($db, 'INSERT INTO ' . collectionFallbackV18Identifier($principalTable) . ' (`id`) VALUES (1)');

    $heritage = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '合成历史照片', '2023-10-18', ['合成相册'], '历史 合成', 930001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101],
    );
    $living = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $livingId, 'LIVING', 'ACTIVE', 'ACTIVE', '合成动态照片', null, ['合成相册'], '动态 合成', 930002, 'UNKNOWN', 'UNKNOWN', null, [101],
    );
    $catalog = $store->rebuildPhotos([$heritage, $living], false, $store->beginPhotoCatalogBuild());
    $assert(($catalog['changed'] ?? null) === true && ($catalog['count'] ?? null) === 2, 'catalog_publish_failed');

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
                'coverPhotoId' => $livingId, 'photo_ids' => [$heritageId, $livingId],
                'sourceLabel' => '合成来源', 'eventLabel' => '合成活动', 'dateLabel' => '班级历史',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 1, 'items' => [[
                'id' => $personId, 'label' => '合成人物', 'photo_count' => 2,
                'cover_photo_id' => $livingId, 'photo_ids' => [$heritageId, $livingId],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 1, 'items' => [[
                'label' => '合成回忆', 'kind' => 'EVENT', 'photo_count' => 2,
                'cover_photo_id' => $livingId, 'photo_ids' => [$heritageId, $livingId], 'source_reason' => 'MEMORY:full-fixture',
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
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
                'id' => $personId, 'label' => '合成人物', 'photo_count' => 1,
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
    $assert(($aggregate['changed'] ?? null) === true && count((array) ($aggregate['changed_kinds'] ?? [])) === 5, 'aggregate_publish_failed');

    $fullEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
    $heritageEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE);
    $adversarialHome = [[
        'itemKind' => \ClassIdentity\CollectionSnapshotService::ITEM_PERSON,
        'itemKey' => 'person-a',
        'coverPhotoId' => $livingId,
        'photoIds' => [$heritageId, $livingId],
        'payload' => ['section' => 'PERSON', 'title' => '合成人物'],
    ]];
    $bundle = [
        \ClassIdentity\CollectionSnapshotService::KIND_HOME => $adversarialHome,
        \ClassIdentity\CollectionSnapshotService::KIND_MEMORY => [],
        \ClassIdentity\CollectionSnapshotService::KIND_SPOTLIGHT => [],
        \ClassIdentity\CollectionSnapshotService::KIND_SEARCH_SUGGESTION => [],
    ];
    $publishedFull = $snapshots->publishBundle(\ClassIdentity\CollectionSnapshotService::SCOPE_FULL, $fullEpoch, $bundle);
    $publishedHeritage = $snapshots->publishBundle(\ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY, $heritageEpoch, $bundle);
    $assert(count($publishedFull) === 4 && count($publishedHeritage) === 4
        && array_reduce($publishedFull, static fn(bool $carry, array $row): bool => $carry && ($row['published'] ?? null) === true, true)
        && array_reduce($publishedHeritage, static fn(bool $carry, array $row): bool => $carry && ($row['published'] ?? null) === true, true), 'four_kind_bundle_publish_failed');

    // Advance the real aggregate epoch without rebuilding snapshots, creating
    // the intended retained-active fallback window.
    $changedPayloads = $payloads;
    $changedPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]['items'][0]['eventLabel'] = '调整后的合成活动';
    $changedPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE][\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]['items'][0]['eventLabel'] = '调整后的合成活动';
    $advanced = $store->rebuildAggregates(
        $changedPayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS],
        $store->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::ALBUMS]),
    );
    $newFullEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
    $newHeritageEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE);
    $assert(($advanced['changed'] ?? null) === true && !hash_equals($fullEpoch, $newFullEpoch)
        && !hash_equals($heritageEpoch, $newHeritageEpoch), 'stale_epoch_not_created');

    $piwigo = new CollectionFallbackV18PiwigoAdapter([$heritage, $living]);
    $immich = new CollectionFallbackV18ImmichAdapter();
    $familyGateway = new \ClassIdentity\Gateway\GatewayService(
        new CollectionFallbackV18IdentityAdapter(new \ClassIdentity\Gateway\GatewayPrincipal(\ClassIdentity\Access::ROLE_FAMILY)),
        $piwigo,
        $immich,
        new \ClassIdentity\Gateway\GatewayPolicy(),
        readProjection: $store,
        collectionSnapshotDomain: $snapshots,
    );
    $classmateGateway = new \ClassIdentity\Gateway\GatewayService(
        new CollectionFallbackV18IdentityAdapter(new \ClassIdentity\Gateway\GatewayPrincipal(\ClassIdentity\Access::ROLE_CLASSMATE)),
        $piwigo,
        $immich,
        new \ClassIdentity\Gateway\GatewayPolicy(),
        readProjection: $store,
        collectionSnapshotDomain: $snapshots,
    );
    $bundleMethod = new ReflectionMethod(\ClassIdentity\Gateway\GatewayService::class, 'publishedCollectionSnapshotBundle');
    $recheckMethod = new ReflectionMethod(\ClassIdentity\Gateway\GatewayService::class, 'recheckCollectionSnapshotItem');
    $familyBundle = $bundleMethod->invoke($familyGateway, \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY, $newHeritageEpoch, true);
    $classmateBundle = $bundleMethod->invoke($classmateGateway, \ClassIdentity\CollectionSnapshotService::SCOPE_FULL, $newFullEpoch, true);
    $assert(($familyBundle['mode'] ?? null) === 'FALLBACK' && ($classmateBundle['mode'] ?? null) === 'FALLBACK', 'retained_bundle_not_accepted_for_read');

    $familyHome = $snapshots->activeSnapshot(
        \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY,
        \ClassIdentity\CollectionSnapshotService::KIND_HOME,
        static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $recheckMethod->invoke($familyGateway, $item),
    );
    $classmateHome = $snapshots->activeSnapshot(
        \ClassIdentity\CollectionSnapshotService::SCOPE_FULL,
        \ClassIdentity\CollectionSnapshotService::KIND_HOME,
        static fn(\ClassIdentity\CollectionSnapshotItem $item): ?array => $recheckMethod->invoke($classmateGateway, $item),
    );
    $familyItem = $familyHome['items'][0] ?? null;
    $classmateItem = $classmateHome['items'][0] ?? null;
    $familyJson = json_encode($familyHome, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $assert(is_array($familyItem)
        && ($familyItem['photoIds'] ?? null) === [$heritageId]
        && ($familyItem['photoCount'] ?? null) === 1
        && ($familyItem['coverPhotoId'] ?? null) === $heritageId
        && !str_contains($familyJson, $livingId), 'family_fallback_acl_leaked_living');
    $assert(is_array($classmateItem)
        && ($classmateItem['photoIds'] ?? null) === [$heritageId, $livingId]
        && ($classmateItem['photoCount'] ?? null) === 2
        && ($classmateItem['coverPhotoId'] ?? null) === $livingId, 'full_fallback_acl_lost_visible_media');
    $assert($piwigo->fullScanCalls === 0 && $piwigo->pointCalls === 4, 'fallback_acl_point_read_contract_failed');

    try {
        $bundleMethod->invoke($familyGateway, \ClassIdentity\CollectionSnapshotService::SCOPE_HERITAGE_ONLY, $newHeritageEpoch, false);
        collectionFallbackV18Fail('strict_read_stale_bundle_accepted');
    } catch (ReflectionException $error) {
        throw $error;
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_collection_snapshot_stale', 'strict_read_wrong_failure');
    }

    try {
        $familyGateway->pinCollection(\ClassIdentity\CollectionSnapshotService::KIND_HOME, \ClassIdentity\CollectionSnapshotService::ITEM_PERSON, 'person-a');
        collectionFallbackV18Fail('strict_mutation_stale_bundle_accepted');
    } catch (RuntimeException $error) {
        $assert($error->getMessage() === 'class_archive_collection_snapshot_stale', 'strict_mutation_wrong_failure');
    }
    $pins = collectionFallbackV18One($db, 'SELECT COUNT(*) AS `count` FROM ' . collectionFallbackV18Identifier($ci . 'collection_pin'));
    $assert((int) ($pins['count'] ?? -1) === 0, 'stale_mutation_created_pin');

    fwrite(STDOUT, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=PASS assertions={$assertions} run={$run}\n");
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME=FAIL run=' . $run . ' reason='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    $like = $db->real_escape_string($ci) . '%';
    $tables = $db->query("SELECT `TABLE_NAME` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $names = [];
    if ($tables instanceof mysqli_result) {
        while ($row = $tables->fetch_assoc()) {
            if (is_array($row) && is_string($row['TABLE_NAME'] ?? null)) {
                $names[] = $row['TABLE_NAME'];
            }
        }
        $tables->free();
    }
    foreach (array_reverse($names) as $name) {
        $db->query('DROP TABLE IF EXISTS ' . collectionFallbackV18Identifier($name));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME_CLEANUP=FAIL run=' . $run . ' reason='
                . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n");
            $exit = 1;
        }
    }
    $remaining = collectionFallbackV18One($db, "SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    if ((int) ($remaining['count'] ?? -1) !== 0) {
        fwrite(STDERR, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME_CLEANUP=FAIL run={$run} remaining=" . (int) ($remaining['count'] ?? -1) . "\n");
        $exit = 1;
    } elseif ($exit === 0) {
        fwrite(STDOUT, "COLLECTION_SNAPSHOT_FALLBACK_V18_RUNTIME_CLEANUP=PASS run={$run}\n");
    }
    $db->close();
}

exit($exit);
