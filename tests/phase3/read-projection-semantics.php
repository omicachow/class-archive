<?php

declare(strict_types=1);

function projectionFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_READ_PROJECTION=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_READ_PROJECTION=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "CLASS_ARCHIVE_READ_PROJECTION=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/CoreAdapter.php';
require $root . '/plugins/ClassIdentity/src/Access.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/AutoCollectionService.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/plugins/ClassIdentity/src/ProjectionMutationBoundary.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhotoMappingService.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

final class ProjectionIdentity implements \ClassIdentity\Gateway\IdentityAdapter
{
    public function __construct(private readonly string $role) {}
    public function currentPrincipal(): ?\ClassIdentity\Gateway\GatewayPrincipal
    {
        return new \ClassIdentity\Gateway\GatewayPrincipal($this->role);
    }
}

final class ProjectionPiwigo implements \ClassIdentity\Gateway\PointPiwigoAdapter
{
    public function __construct(private readonly \ClassIdentity\Gateway\ReadProjectionStore $store) {}
    public function photoCandidates(): array { return $this->store->photos(); }
    public function photoCandidate(string $classPhotoId): ?\ClassIdentity\Gateway\GatewayPhotoCandidate
    {
        return $this->store->photo($classPhotoId);
    }
}

final class ProjectionImmich implements \ClassIdentity\Gateway\ImmichAdapter
{
    public function availability(): string { return 'UNAVAILABLE'; }
    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array { return []; }
    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array { return []; }
    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array { return []; }
}

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_read_sem_' . $run . '_';
$schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
$assertions = 0;
$exit = 0;
$triggerNames = [];
$createdNative = [];
try {
    $migration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection');
    $migration->invoke($schema);
    $aggregateMigration = new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection');
    $aggregateMigration->invoke($schema);
    $createdNative = classIdentityCreateNativeProjectionFixture($db, (string) $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);
    $digest = new ReflectionMethod(\ClassIdentity\Schema::class, 'semanticDigest');
    $expected = new ReflectionMethod(\ClassIdentity\Schema::class, 'expectedSemanticDigests');
    $digests = $expected->invoke(null);
    foreach (['read_projection', 'read_photo'] as $suffix) {
        if (!hash_equals((string) $digests[$suffix], (string) $digest->invoke($schema, $suffix))) {
            projectionFail('read_projection_semantic_digest_' . $suffix);
        }
        ++$assertions;
    }

    $allKinds = \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds();
    if (\ClassIdentity\ProjectionMutationBoundary::archiveKinds([
        'add_album_ids' => ['10000000-0000-4000-8000-000000000099'],
    ]) !== $allKinds) {
        projectionFail('read_projection_native_album_add_recovery_scope_invalid');
    }
    if (\ClassIdentity\ProjectionMutationBoundary::archiveKinds([
        'remove_album_ids' => ['10000000-0000-4000-8000-000000000099'],
    ]) !== $allKinds) {
        projectionFail('read_projection_native_album_remove_recovery_scope_invalid');
    }
    if (\ClassIdentity\ProjectionMutationBoundary::archiveKinds([
        'archive_date' => '2023-10-18',
    ]) !== [
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
    ]) {
        projectionFail('read_projection_metadata_dependency_scope_invalid');
    }
    $assertions += 3;

    $heritageId = '10000000-0000-4000-8000-000000000001';
    $livingId = '10000000-0000-4000-8000-000000000002';
    $personId = '20000000-0000-4000-8000-000000000001';
    $spotlightId = '30000000-0000-4000-8000-000000000001';
    $spotlightAlbumId = '40000000-0000-4000-8000-000000000001';
    $photos = [
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '历史照片', '2023-10-18', ['班级档案'], '历史照片', 900001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101]),
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($livingId, 'LIVING', 'ACTIVE', 'ACTIVE', '毕业动态', null, ['后来'], '毕业动态', 900002, 'UNKNOWN', 'UNKNOWN', null, [102]),
    ];
    $heritageProjection = $photos[0]->publicProjection();
    $livingProjection = $photos[1]->publicProjection();
    $aggregatePayloads = [
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 2, 'groups' => [[
                'key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH',
                'total' => 1, 'items' => [$heritageProjection],
            ], [
                'key' => 'unknown', 'label' => '日期未知', 'kind' => 'UNKNOWN',
                'total' => 1, 'items' => [$livingProjection],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 1, 'items' => [[
                'id' => $personId,
                'label' => '测试人物',
                'photo_count' => 2,
                'cover_photo_id' => $heritageId,
                'photo_ids' => [$heritageId, $livingId],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => true, 'total' => 1, 'item' => [
                'id' => $spotlightId,
                'albumId' => $spotlightAlbumId,
                'albumName' => '毕业后测试精选',
                'coverPhotoId' => $livingId,
                'description' => '仅完整范围可见的合成精选',
                'publisherLabel' => '班级成员',
                'expiresAt' => '2099-01-01 00:00:00.000000',
            ]],
        ],
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 1, 'groups' => [[
                'key' => 'month:2023-10', 'label' => '2023年10月', 'kind' => 'MONTH',
                'total' => 1, 'items' => [$heritageProjection],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 1, 'items' => [[
                'id' => $personId,
                'label' => '测试人物',
                'photo_count' => 1,
                'cover_photo_id' => $heritageId,
                'photo_ids' => [$heritageId],
            ]]],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
    ];
    $publishAggregates = static function (
        \ClassIdentity\Gateway\ReadProjectionStore $projectionStore,
        array $payloads,
        array $kinds = [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
        ],
    ): array {
        $token = $projectionStore->beginAggregateBuild($kinds);
        return $projectionStore->rebuildAggregates($payloads, $kinds, $token);
    };
    $rebuildCatalog = static function (
        \ClassIdentity\Gateway\ReadProjectionStore $projectionStore,
        array $candidates,
        bool $dryRun = false,
    ): array {
        $token = $projectionStore->beginPhotoCatalogBuild();
        return $projectionStore->rebuildPhotos($candidates, $dryRun, $token);
    };
    $refreshCatalog = static function (
        \ClassIdentity\Gateway\ReadProjectionStore $projectionStore,
        array $candidates,
        array $kinds,
    ): array {
        $token = $projectionStore->beginPhotoCatalogBuild();
        return $projectionStore->refreshPhotos($candidates, $kinds, $token);
    };

    $dry = $rebuildCatalog($store, $photos, true);
    if (($dry['changed'] ?? null) !== true || ($dry['dry_run'] ?? null) !== true) projectionFail('read_projection_dry_run_invalid');
    try {
        $store->photos();
        projectionFail('read_projection_missing_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_unavailable') throw $error;
    }
    $assertions += 2;

    $built = $rebuildCatalog($store, $photos);
    if (($built['changed'] ?? null) !== true || ($built['count'] ?? null) !== 2) projectionFail('read_projection_build_invalid');
    $restartStore = new \ClassIdentity\Gateway\ReadProjectionStore(new \ClassIdentity\Repository($db, $basePrefix));
    if (count($restartStore->photos()) !== 2 || $restartStore->photo($heritageId)?->piwigoImageIdForDelivery() !== 900001) {
        projectionFail('read_projection_restart_or_point_read_failed');
    }
    $again = $rebuildCatalog($restartStore, $photos);
    if (($again['changed'] ?? null) !== false) projectionFail('read_projection_not_idempotent');
    $aggregateBuild = $publishAggregates($restartStore, $aggregatePayloads);
    if (($aggregateBuild['changed'] ?? null) !== true || count($aggregateBuild['changed_kinds'] ?? []) !== 5) {
        projectionFail('read_aggregate_build_invalid');
    }
    $states = [];
    foreach ($restartStore->status() as $state) $states[$state['kind']] = $state['state'];
    if (($states['PHOTO_CATALOG'] ?? null) !== 'ACTIVE'
        || ($states['TIMELINE'] ?? null) !== 'ACTIVE'
        || ($states['ALBUMS'] ?? null) !== 'ACTIVE'
        || ($states['PEOPLE'] ?? null) !== 'ACTIVE'
        || ($states['MEMORIES'] ?? null) !== 'ACTIVE'
        || ($states['SPOTLIGHT'] ?? null) !== 'ACTIVE'
    ) projectionFail('read_aggregate_status_not_persistent');
    $assertions += 6;

    // A Memory rebuild first commits STALE, then synchronizes AutoCollection
    // inside the same aggregate publish transaction. Inject a malformed
    // candidate through the real service and prove MariaDB rolls back the
    // sync while MEMORIES remains fail-closed. The exact bounded
    // {available:false,total:0,items:[]} payload is intentionally a safe
    // no-op for an optional source outage and is covered separately.
    $autoCollectionTable = '`' . $repository->table('auto_collection') . '`';
    $autoMemberTable = '`' . $repository->table('auto_collection_photo') . '`';
    if ($db->query(
        "CREATE TABLE {$autoCollectionTable} ("
            . '`auto_collection_id` BINARY(16) NOT NULL,`collection_kind` VARCHAR(16) NOT NULL,'
            . '`title` VARCHAR(190) NOT NULL,`subtitle` VARCHAR(190) NULL,`source_reason` VARCHAR(64) NOT NULL,'
            . '`archive_date` DATE NULL,`date_precision` VARCHAR(16) NOT NULL,`cover_class_photo_id` BINARY(16) NOT NULL,'
            . '`visibility_scope` VARCHAR(24) NOT NULL,`projection_revision` BINARY(32) NOT NULL,`state` VARCHAR(16) NOT NULL,'
            . '`generated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . '`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (`auto_collection_id`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ) === false || $db->query(
        "CREATE TABLE {$autoMemberTable} ("
            . '`auto_collection_id` BINARY(16) NOT NULL,`class_photo_id` BINARY(16) NOT NULL,`ordinal` INT UNSIGNED NOT NULL,'
            . '`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),'
            . 'PRIMARY KEY (`auto_collection_id`,`class_photo_id`),UNIQUE KEY (`auto_collection_id`,`ordinal`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    ) === false) {
        projectionFail('memory_barrier_fixture_create_failed');
    }
    $validMemory = ['available' => true, 'total' => 0, 'items' => []];
    $invalidMemory = ['available' => false, 'total' => 1, 'items' => []];
    $barrierPayloads = $aggregatePayloads;
    $barrierPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES] = $invalidMemory;
    $barrierPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE][\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES] = ['available' => true, 'total' => 0, 'items' => []];
    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES], 'AUTO_COLLECTION_REBUILD_STARTED', false);
    $barrierToken = $restartStore->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES]);
    try {
        $restartStore->rebuildAggregates(
            $barrierPayloads,
            [\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
            $barrierToken,
            false,
            static fn(\ClassIdentity\Repository $transactionRepository): array =>
                (new \ClassIdentity\AutoCollectionService($transactionRepository))
                    ->syncMemoryProjectionInCurrentTransaction($invalidMemory),
        );
        projectionFail('memory_barrier_sync_failure_published');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_auto_collection_projection_unavailable') throw $error;
    }
    $memoryState = array_values(array_filter(
        $restartStore->status(),
        static fn(array $row): bool => ($row['kind'] ?? null) === \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
    ))[0] ?? null;
    if (($memoryState['state'] ?? null) !== 'STALE') projectionFail('memory_barrier_failure_not_stale');
    if ((int) (($repository->fetchOne("SELECT COUNT(*) AS `count` FROM {$autoCollectionTable}")['count'] ?? -1)) !== 0) {
        projectionFail('memory_barrier_failure_did_not_rollback_auto_collection');
    }
    try {
        $restartStore->aggregate(\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES, \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
        projectionFail('memory_barrier_stale_payload_readable');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') throw $error;
    }
    $barrierPayloads[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES] = $validMemory;
    $barrierToken = $restartStore->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES]);
    $barrierRecovered = $restartStore->rebuildAggregates(
        $barrierPayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
        $barrierToken,
        false,
        static fn(\ClassIdentity\Repository $transactionRepository): array =>
            (new \ClassIdentity\AutoCollectionService($transactionRepository))
                ->syncMemoryProjectionInCurrentTransaction($validMemory),
    );
    if (($barrierRecovered['pre_publish_result']['total'] ?? null) !== 0
        || ($restartStore->aggregate(\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES, \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL)['total'] ?? null) !== 0
    ) projectionFail('memory_barrier_recovery_failed');

    // Restore the original empty fixture through the same barrier so later
    // projection semantics remain independent of this fault injection.
    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES], 'AUTO_COLLECTION_REBUILD_STARTED', false);
    $restoreMemoryToken = $restartStore->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES]);
    $restartStore->rebuildAggregates(
        $aggregatePayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
        $restoreMemoryToken,
        false,
        static fn(\ClassIdentity\Repository $transactionRepository): array =>
            (new \ClassIdentity\AutoCollectionService($transactionRepository))
                ->syncMemoryProjectionInCurrentTransaction(['available' => true, 'total' => 0, 'items' => []]),
    );
    $assertions += 5;

    // Point refreshes must declare which aggregates depend on the changed
    // photo. An empty dependency set could otherwise rebind stale counts to a
    // fresh catalog generation.
    try {
        \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
            $repository,
            [],
            'EMPTY_DEPENDENCY_TEST',
        );
        projectionFail('read_projection_empty_invalidation_dependencies_accepted');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'class_archive_projection_dependencies_missing') throw $error;
    }
    try {
        $refreshCatalog($restartStore, [$photos[0]], []);
        projectionFail('read_projection_empty_refresh_dependencies_accepted');
    } catch (InvalidArgumentException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_refresh_dependencies_missing') throw $error;
    }
    $assertions += 2;

    // A no-write dry run is still a security/status assertion. If its kind
    // epoch changes while payloads are being computed, the stale token must be
    // rejected instead of returning an apparently current no-change result.
    $dryAggregateToken = $restartStore->beginAggregateBuild([
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    ]);
    $restartStore->invalidate([
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    ], 'DRY_RUN_EPOCH_TEST', false);
    try {
        $restartStore->rebuildAggregates(
            $aggregatePayloads,
            [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE],
            $dryAggregateToken,
            true,
        );
        projectionFail('read_aggregate_dry_run_stale_token_accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_source_epoch_changed') throw $error;
    }
    $publishAggregates($restartStore, $aggregatePayloads, [
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    ]);
    ++$assertions;

    // Simulate a native Piwigo write landing after the full source scan but
    // before catalog publication. The stale scan token must be rejected, the
    // existing rows must remain untouched, and a fresh-token rebuild must be
    // able to recover the complete generation.
    $nativeRaceToken = $restartStore->beginPhotoCatalogBuild();
    $nativeRaceRowsBefore = $repository->fetchAll(
        'SELECT HEX(`class_photo_id`) AS `id`,HEX(`generation`) AS `generation`,`payload_json` FROM `'
            . $repository->table('read_photo') . '` ORDER BY `class_photo_id`',
    );
    $repository->execute(
        'UPDATE `' . $repository->table('read_projection') . "` SET `state`='STALE',`generation`=RANDOM_BYTES(16),"
            . "`invalidated_reason`='NATIVE_PIWIGO_MUTATION',`invalidated_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) "
            . "WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES')",
    );
    try {
        $restartStore->rebuildPhotos($photos, false, $nativeRaceToken);
        projectionFail('read_projection_native_epoch_race_not_rejected');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_source_epoch_changed') throw $error;
    }
    $nativeRaceRowsAfter = $repository->fetchAll(
        'SELECT HEX(`class_photo_id`) AS `id`,HEX(`generation`) AS `generation`,`payload_json` FROM `'
            . $repository->table('read_photo') . '` ORDER BY `class_photo_id`',
    );
    if ($nativeRaceRowsAfter !== $nativeRaceRowsBefore) {
        projectionFail('read_projection_native_epoch_race_mutated_rows');
    }
    $nativeRaceStates = [];
    foreach ($restartStore->status() as $state) $nativeRaceStates[$state['kind']] = $state['state'];
    foreach (['PHOTO_CATALOG', 'TIMELINE', 'ALBUMS', 'PEOPLE', 'MEMORIES'] as $kind) {
        if (($nativeRaceStates[$kind] ?? null) !== 'STALE') {
            projectionFail('read_projection_native_epoch_race_reactivated_' . strtolower($kind));
        }
    }
    $rebuildCatalog($restartStore, $photos);
    $publishAggregates($restartStore, $aggregatePayloads);
    $assertions += 4;

    $family = new \ClassIdentity\Gateway\GatewayService(
        new ProjectionIdentity(\ClassIdentity\Access::ROLE_FAMILY),
        new ProjectionPiwigo($restartStore),
        new ProjectionImmich(),
        readProjection: $restartStore,
    );
    if ($family->photo($heritageId) === null || $family->photo($livingId) !== null) {
        projectionFail('read_projection_cached_authorization_result');
    }
    if (($family->timeline()['total'] ?? null) !== 1) projectionFail('read_aggregate_family_scope_leak');
    if (($family->spotlight()['active'] ?? null) !== false || ($family->spotlight()['total'] ?? null) !== 0) {
        projectionFail('read_aggregate_family_spotlight_scope_leak');
    }
    $familyPerson = $family->person($personId);
    if (($familyPerson['photo_count'] ?? null) !== 1 || count($familyPerson['items'] ?? []) !== 1
        || ($familyPerson['items'][0]['id'] ?? null) !== $heritageId
    ) projectionFail('read_aggregate_family_person_hydration_leak');
    $classmate = new \ClassIdentity\Gateway\GatewayService(
        new ProjectionIdentity(\ClassIdentity\Access::ROLE_CLASSMATE),
        new ProjectionPiwigo($restartStore),
        new ProjectionImmich(),
        readProjection: $restartStore,
    );
    if ($classmate->photo($livingId) === null) projectionFail('read_projection_fresh_principal_not_applied');
    if (($classmate->timeline()['total'] ?? null) !== 2) projectionFail('read_aggregate_full_scope_missing');
    $classmateSpotlight = $classmate->spotlight();
    if (($classmateSpotlight['active'] ?? null) !== true
        || ($classmateSpotlight['total'] ?? null) !== 1
        || ($classmateSpotlight['item']['coverPhotoId'] ?? null) !== $livingId
        || ($classmateSpotlight['item']['albumId'] ?? null) !== $spotlightAlbumId
    ) {
        projectionFail('read_aggregate_full_spotlight_missing');
    }
    $classmatePerson = $classmate->person($personId);
    if (($classmatePerson['photo_count'] ?? null) !== 2 || count($classmatePerson['items'] ?? []) !== 2) {
        projectionFail('read_aggregate_full_person_hydration_missing');
    }
    $assertions += 8;

    // Fault injection proves that publishing a replacement catalog and
    // invalidating every dependent aggregate are one InnoDB commit. The
    // trigger fires after replacement rows have been written but before the
    // catalog can publish; every write made earlier in rebuildPhotos() must
    // therefore roll back as well.
    $metaTable = '`' . $repository->table('read_projection') . '`';
    $rowTable = '`' . $repository->table('read_photo') . '`';
    $atomicBeforeMeta = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "`item_count`,`payload_json`,HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` FROM {$metaTable} ORDER BY `projection_key`",
    );
    $atomicBeforeRows = $repository->fetchAll(
        "SELECT HEX(`class_photo_id`) AS `class_photo_id`,`piwigo_image_id`,`era`,`payload_json`,HEX(`row_digest`) AS `row_digest`,"
            . "HEX(`generation`) AS `generation`,`built_at` FROM {$rowTable} ORDER BY `class_photo_id`",
    );
    $atomicFaultTrigger = $basePrefix . 'fault_catalog_atomicity';
    $triggerNames[] = $atomicFaultTrigger;
    if ($db->query(
        'CREATE TRIGGER `' . $atomicFaultTrigger . '` BEFORE UPDATE ON ' . $metaTable . ' FOR EACH ROW '
            . "BEGIN IF OLD.`projection_key`='TIMELINE' AND NEW.`state`='STALE' "
            . "AND NEW.`invalidated_reason`='PHOTO_CATALOG_CHANGED' THEN "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='class_archive_projection_fault_injected'; END IF; END",
    ) === false) {
        projectionFail('read_projection_atomic_trigger_create_' . $db->errno);
    }
    $atomicPhotos = [
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '原子回滚测试', '2023-10-18', ['班级档案'], '原子回滚测试', 900001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101]),
        $photos[1],
    ];
    try {
        $rebuildCatalog($restartStore, $atomicPhotos);
        projectionFail('read_projection_catalog_atomic_fault_not_raised');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_identity_query_execute_failed_1644') throw $error;
    }
    if ($db->query('DROP TRIGGER IF EXISTS `' . $atomicFaultTrigger . '`') === false) {
        projectionFail('read_projection_atomic_trigger_drop_' . $db->errno);
    }
    $triggerNames = array_values(array_diff($triggerNames, [$atomicFaultTrigger]));
    $atomicAfterMeta = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "`item_count`,`payload_json`,HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` FROM {$metaTable} ORDER BY `projection_key`",
    );
    $atomicAfterRows = $repository->fetchAll(
        "SELECT HEX(`class_photo_id`) AS `class_photo_id`,`piwigo_image_id`,`era`,`payload_json`,HEX(`row_digest`) AS `row_digest`,"
            . "HEX(`generation`) AS `generation`,`built_at` FROM {$rowTable} ORDER BY `class_photo_id`",
    );
    if ($atomicAfterMeta !== $atomicBeforeMeta || $atomicAfterRows !== $atomicBeforeRows
        || ($restartStore->photo($heritageId)?->readModelProjection()['title'] ?? null) !== '历史照片'
        || ($restartStore->aggregate(\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL)['total'] ?? null) !== 2
    ) {
        projectionFail('read_projection_catalog_atomic_rollback_failed');
    }
    $assertions += 4;

    // A builder may spend minutes computing an aggregate. If the catalog is
    // replaced during that work, the old catalog token must never reactivate
    // an aggregate, even when the payload itself still looks structurally
    // valid and has the same item counts.
    $staleBuildToken = $restartStore->beginAggregateBuild([
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    ]);
    $racePhotos = [
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($heritageId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '目录代际切换', '2023-10-18', ['班级档案'], '目录代际切换', 900001, 'DAY', 'ARCHIVE_CONFIRMED', null, [101]),
        $photos[1],
    ];
    $rebuildCatalog($restartStore, $racePhotos);
    $raceBeforePublish = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision` "
            . "FROM {$metaTable} WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE') ORDER BY `projection_key`",
    );
    try {
        $restartStore->rebuildAggregates(
            $aggregatePayloads,
            [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE],
            $staleBuildToken,
        );
        projectionFail('read_aggregate_stale_catalog_token_not_rejected');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_catalog_publish_race') throw $error;
    }
    $raceAfterPublish = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision` "
            . "FROM {$metaTable} WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE') ORDER BY `projection_key`",
    );
    if ($raceAfterPublish !== $raceBeforePublish) {
        projectionFail('read_aggregate_stale_catalog_token_mutated_state');
    }
    try {
        $restartStore->aggregate(\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
        projectionFail('read_aggregate_stale_catalog_token_reactivated');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') throw $error;
    }
    // Restore the original deterministic fixture for subsequent dependency
    // and tamper tests.
    $rebuildCatalog($restartStore, $photos);
    $publishAggregates($restartStore, $aggregatePayloads);
    $assertions += 4;

    // Direct service calls (including CLI/native Admin callers that bypass an
    // HTTP controller) must share the same transaction boundary. Inject an
    // invalidation failure and prove the canonical mapping mutation does not
    // commit and the previously ACTIVE projection remains byte-for-byte intact.
    $photoTable = '`' . $repository->table('photo') . '`';
    if ($db->query(
        "CREATE TABLE {$photoTable} ("
            . "`class_photo_id` BINARY(16) NOT NULL,`piwigo_image_id` MEDIUMINT UNSIGNED NULL,"
            . "`source_submission_id` BIGINT UNSIGNED NULL,`immich_asset_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,"
            . "`media_checksum` BINARY(32) NOT NULL,`media_reference` VARCHAR(512) NOT NULL,"
            . "`state` VARCHAR(16) NOT NULL,`created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),"
            . "`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (`class_photo_id`),"
            . "UNIQUE KEY `uq_test_photo_image` (`piwigo_image_id`),UNIQUE KEY `uq_test_photo_immich` (`immich_asset_id`)) "
            . "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ) === false) {
        projectionFail('read_projection_business_photo_table_' . $db->errno);
    }
    $businessPhotoId = '30000000-0000-4000-8000-000000000001';
    $repository->execute(
        "INSERT INTO {$photoTable} (`class_photo_id`,`piwigo_image_id`,`media_checksum`,`media_reference`,`state`) VALUES (?,?,?,?,?)",
        [
            \ClassIdentity\ClassArchivePhoto::idToBinary($businessPhotoId),
            990001,
            hash('sha256', 'business-projection-atomicity', true),
            './upload/2026/08/24/business-projection-atomicity.jpg',
            \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        ],
    );
    $peopleBeforeFailure = $repository->fetchOne(
        "SELECT `state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "`payload_json`,HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`invalidated_at`,`updated_at` FROM {$metaTable} WHERE `projection_key`='PEOPLE'",
    );
    $businessFaultTrigger = $basePrefix . 'fault_business_invalidation';
    $triggerNames[] = $businessFaultTrigger;
    if ($db->query(
        'CREATE TRIGGER `' . $businessFaultTrigger . '` BEFORE UPDATE ON ' . $metaTable . ' FOR EACH ROW '
            . "BEGIN IF OLD.`projection_key`='PEOPLE' AND NEW.`state`='STALE' "
            . "AND NEW.`invalidated_reason`='IMMICH_ASSET_BIND' THEN "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='class_archive_business_projection_fault'; END IF; END",
    ) === false) {
        projectionFail('read_projection_business_trigger_create_' . $db->errno);
    }
    try {
        (new \ClassIdentity\ClassArchivePhotoMappingService($repository))->bindImmichAsset(
            $businessPhotoId,
            '40000000-0000-4000-8000-000000000001',
        );
        projectionFail('read_projection_business_invalidation_fault_not_raised');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_identity_query_execute_failed_1644') throw $error;
    }
    if ($db->query('DROP TRIGGER IF EXISTS `' . $businessFaultTrigger . '`') === false) {
        projectionFail('read_projection_business_trigger_drop_' . $db->errno);
    }
    $triggerNames = array_values(array_diff($triggerNames, [$businessFaultTrigger]));
    $businessAfterFailure = $repository->fetchOne(
        "SELECT `immich_asset_id`,`state` FROM {$photoTable} WHERE `class_photo_id`=? LIMIT 1",
        [\ClassIdentity\ClassArchivePhoto::idToBinary($businessPhotoId)],
    );
    $peopleAfterFailure = $repository->fetchOne(
        "SELECT `state`,HEX(`source_revision`) AS `source_revision`,HEX(`generation`) AS `generation`,"
            . "`payload_json`,HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`invalidated_at`,`updated_at` FROM {$metaTable} WHERE `projection_key`='PEOPLE'",
    );
    if (($businessAfterFailure['immich_asset_id'] ?? null) !== null
        || ($businessAfterFailure['state'] ?? null) !== \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE
        || $peopleAfterFailure !== $peopleBeforeFailure
    ) {
        projectionFail('read_projection_business_invalidation_rollback_failed');
    }
    $assertions += 3;

    $beforeIncremental = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`generation`) AS `generation`,HEX(`source_revision`) AS `source_revision` "
            . "FROM {$metaTable} WHERE `projection_key` IN ('PHOTO_CATALOG','TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`",
    );
    $beforeByKind = [];
    foreach ($beforeIncremental as $row) $beforeByKind[(string) $row['projection_key']] = $row;
    $untouchedBefore = $repository->fetchOne(
        "SELECT HEX(`row_digest`) AS `row_digest`,`built_at` FROM {$rowTable} WHERE `class_photo_id`=? LIMIT 1",
        [\ClassIdentity\ClassArchivePhoto::idToBinary($livingId)],
    );
    $changedHeritage = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $heritageId,
        'HERITAGE',
        'ACTIVE',
        'ACTIVE',
        '历史照片（增量）',
        '2023-10-19',
        ['班级档案'],
        '历史照片 增量',
        900001,
        'DAY',
        'ARCHIVE_CONFIRMED',
        null,
        [101],
    );
    $restartStore->invalidate([
        \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
    ], 'TEST_POINT_REFRESH', false);
    $point = $refreshCatalog(
        $restartStore,
        [$changedHeritage],
        [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
    );
    if (($point['updated'] ?? null) !== 1 || ($point['count'] ?? null) !== 2
        || ($point['generation'] ?? null) !== strtolower((string) ($beforeByKind['PHOTO_CATALOG']['generation'] ?? ''))
        || ($point['source_revision'] ?? null) === strtolower((string) ($beforeByKind['PHOTO_CATALOG']['source_revision'] ?? ''))
    ) projectionFail('read_projection_point_refresh_invalid');
    $pointStates = [];
    foreach ($restartStore->status() as $state) $pointStates[$state['kind']] = $state['state'];
    if (($pointStates['PHOTO_CATALOG'] ?? null) !== 'ACTIVE'
        || ($pointStates['TIMELINE'] ?? null) !== 'STALE'
        || ($pointStates['MEMORIES'] ?? null) !== 'STALE'
        || ($pointStates['ALBUMS'] ?? null) !== 'ACTIVE'
        || ($pointStates['PEOPLE'] ?? null) !== 'ACTIVE'
        || ($pointStates['SPOTLIGHT'] ?? null) !== 'ACTIVE'
    ) projectionFail('read_projection_point_refresh_dependency_scope');
    $untouchedAfter = $repository->fetchOne(
        "SELECT HEX(`row_digest`) AS `row_digest`,`built_at` FROM {$rowTable} WHERE `class_photo_id`=? LIMIT 1",
        [\ClassIdentity\ClassArchivePhoto::idToBinary($livingId)],
    );
    if ($untouchedBefore !== $untouchedAfter
        || ($restartStore->photo($heritageId)?->readModelProjection()['title'] ?? null) !== '历史照片（增量）'
    ) projectionFail('read_projection_point_refresh_touched_unrelated_row');
    $publishAggregates(
        $restartStore,
        $aggregatePayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
    );
    $afterAggregate = $repository->fetchAll(
        "SELECT `projection_key`,HEX(`generation`) AS `generation` FROM {$metaTable} "
            . "WHERE `projection_key` IN ('TIMELINE','ALBUMS','PEOPLE','MEMORIES','SPOTLIGHT') ORDER BY `projection_key`",
    );
    $afterByKind = [];
    foreach ($afterAggregate as $row) $afterByKind[(string) $row['projection_key']] = $row;
    foreach (['TIMELINE', 'MEMORIES'] as $kind) {
        if (($afterByKind[$kind]['generation'] ?? null) === ($beforeByKind[$kind]['generation'] ?? null)) {
            projectionFail('read_projection_point_refresh_affected_generation_' . strtolower($kind));
        }
    }
    foreach (['ALBUMS', 'PEOPLE', 'SPOTLIGHT'] as $kind) {
        if (($afterByKind[$kind]['generation'] ?? null) !== ($beforeByKind[$kind]['generation'] ?? null)) {
            projectionFail('read_projection_point_refresh_unrelated_generation_' . strtolower($kind));
        }
    }
    $restartStore->invalidate([
        \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
    ], 'TEST_POINT_ROLLBACK', false);
    $refreshCatalog(
        $restartStore,
        [$photos[0]],
        [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
    );
    $publishAggregates(
        $restartStore,
        $aggregatePayloads,
        [\ClassIdentity\Gateway\ReadProjectionStore::TIMELINE, \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES],
    );
    $restoredCatalog = $repository->fetchOne(
        "SELECT HEX(`generation`) AS `generation`,HEX(`source_revision`) AS `source_revision` FROM {$metaTable} WHERE `projection_key`='PHOTO_CATALOG'",
    );
    if (strtolower((string) ($restoredCatalog['generation'] ?? '')) !== strtolower((string) ($beforeByKind['PHOTO_CATALOG']['generation'] ?? ''))
        || strtolower((string) ($restoredCatalog['source_revision'] ?? '')) !== strtolower((string) ($beforeByKind['PHOTO_CATALOG']['source_revision'] ?? ''))
    ) projectionFail('read_projection_point_refresh_rollback_invalid');
    $assertions += 8;

    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::PEOPLE], 'TEST_PEOPLE');
    if ($restartStore->photo($heritageId) === null) projectionFail('read_projection_dependency_too_broad');
    try {
        $restartStore->aggregate(\ClassIdentity\Gateway\ReadProjectionStore::PEOPLE, \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
        projectionFail('read_aggregate_stale_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') throw $error;
    }
    $publishAggregates($restartStore, $aggregatePayloads, [\ClassIdentity\Gateway\ReadProjectionStore::PEOPLE]);
    $restartStore->invalidate([
        \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    ], 'TEST_SELECTIVE', false);
    $selectiveStates = [];
    foreach ($restartStore->status() as $state) $selectiveStates[$state['kind']] = $state['state'];
    if (($selectiveStates['PHOTO_CATALOG'] ?? null) !== 'STALE'
        || ($selectiveStates['TIMELINE'] ?? null) !== 'STALE'
        || ($selectiveStates['ALBUMS'] ?? null) !== 'ACTIVE'
        || ($selectiveStates['PEOPLE'] ?? null) !== 'ACTIVE'
        || ($selectiveStates['MEMORIES'] ?? null) !== 'ACTIVE'
        || ($selectiveStates['SPOTLIGHT'] ?? null) !== 'ACTIVE'
    ) projectionFail('read_projection_selective_invalidation_failed');
    try {
        $family->people();
        projectionFail('read_projection_stale_catalog_aggregate_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_unavailable') throw $error;
    }
    try {
        $family->person($personId);
        projectionFail('read_projection_stale_catalog_hydration_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_unavailable') throw $error;
    }
    $rebuildCatalog($restartStore, $photos);
    $publishAggregates($restartStore, $aggregatePayloads);
    if (count($family->person($personId)['items'] ?? []) !== 1) projectionFail('read_projection_selective_rebuild_failed');
    $assertions += 4;

    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG], 'TEST_ARCHIVE');
    try {
        $restartStore->photo($heritageId);
        projectionFail('read_projection_stale_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_unavailable') throw $error;
    }
    $rebuildCatalog($restartStore, $photos);
    $publishAggregates($restartStore, $aggregatePayloads);
    $assertions += 4;

    $table = '`' . $repository->table('read_photo') . '`';
    if ($db->query("UPDATE {$table} SET `payload_json`=JSON_SET(`payload_json`,'$.title','tampered') WHERE `class_photo_id`=UNHEX('10000000000040008000000000000001')") === false) {
        projectionFail('read_projection_tamper_setup_failed');
    }
    try {
        $restartStore->photo($heritageId);
        projectionFail('read_projection_tamper_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_row_invalid') throw $error;
    }
    $repaired = $rebuildCatalog($restartStore, $photos);
    if (($repaired['changed'] ?? null) !== true || $restartStore->photo($heritageId) === null) {
        projectionFail('read_projection_tamper_not_repaired');
    }
    $assertions += 2;

    $columns = $repository->fetchAll(
        'SELECT `COLUMN_NAME` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?)',
        [$repository->table('read_projection'), $repository->table('read_photo')],
    );
    $forbidden = ['principal', 'role', 'seat', 'account', 'allow', 'permission'];
    foreach ($columns as $column) {
        $name = strtolower((string) ($column['COLUMN_NAME'] ?? ''));
        foreach ($forbidden as $word) if (str_contains($name, $word)) projectionFail('read_projection_authorization_column_' . $name);
    }
    ++$assertions;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'CLASS_ARCHIVE_READ_PROJECTION=FAIL reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    foreach ($triggerNames as $triggerName) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $triggerName) === 1) {
            $db->query('DROP TRIGGER IF EXISTS `' . $triggerName . '`');
        }
    }
    foreach (['auto_collection_photo', 'auto_collection', 'photo', 'read_photo', 'read_projection', 'native_source_epoch'] as $suffix) {
        $name = $basePrefix . 'class_identity_' . $suffix;
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) === 1) $db->query('DROP TABLE IF EXISTS `' . $name . '`');
    }
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(STDERR, 'CLASS_ARCHIVE_READ_PROJECTION_CLEANUP=FAIL reason=' . $cleanupError->getMessage() . "\n");
            $exit = 1;
        }
    }
    $db->close();
}
if ($exit === 0) fwrite(STDOUT, "CLASS_ARCHIVE_READ_PROJECTION=PASS assertions={$assertions}\n");
exit($exit);
