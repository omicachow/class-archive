<?php

declare(strict_types=1);

/**
 * Deterministic regression for the compensated-saga publication race.
 *
 * A builder is allowed to recover an early PREPARED invalidation while the
 * source still contains its old value. The final source transaction must
 * therefore invalidate again, atomically with the real InnoDB mutation. This
 * test also injects a source-write failure to prove that both operations roll
 * back together. All tables and triggers use a disposable random prefix.
 */

function finalSourceRaceFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_FINAL_SOURCE_RACE=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_FINAL_SOURCE_RACE=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) $conf['db_host'],
    (string) $conf['db_user'],
    (string) $conf['db_password'],
    (string) $conf['db_base'],
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "CLASS_ARCHIVE_FINAL_SOURCE_RACE=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/plugins/ClassIdentity/src/ProjectionMutationBoundary.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_final_race_' . $run . '_';
$schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
$sourceTable = $basePrefix . 'final_source';
$faultTrigger = $basePrefix . 'final_source_fault';
$createdNative = [];
$assertions = 0;
$exit = 0;

$requiredKinds = [
    \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG,
    ...\ClassIdentity\ProjectionMutationBoundary::allAggregateKinds(),
];
$allAggregateKinds = \ClassIdentity\ProjectionMutationBoundary::allAggregateKinds();

$states = static function (\ClassIdentity\Gateway\ReadProjectionStore $projectionStore): array {
    $result = [];
    foreach ($projectionStore->status() as $row) {
        $result[(string) $row['kind']] = $row;
    }
    return $result;
};
$assertStates = static function (array $actual, string $expectedState, ?string $expectedReason = null) use ($requiredKinds): void {
    foreach ($requiredKinds as $kind) {
        if (($actual[$kind]['state'] ?? null) !== $expectedState) {
            finalSourceRaceFail('final_source_state_' . strtolower($kind) . '_' . strtolower($expectedState));
        }
        if ($expectedReason !== null && ($actual[$kind]['reason'] ?? null) !== $expectedReason) {
            finalSourceRaceFail('final_source_reason_' . strtolower($kind));
        }
    }
};

try {
    // Keep this test isolated from the canonical synthetic database while
    // exercising the exact production schema and token implementation.
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection'))->invoke($schema);
    if (!isset($prefixeTable) || !is_string($prefixeTable)) {
        finalSourceRaceFail('piwigo_prefix_unavailable');
    }
    $createdNative = classIdentityCreateNativeProjectionFixture($db, $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);

    if ($db->query(
        'CREATE TABLE `' . $sourceTable . '` ('
            . '`id` TINYINT UNSIGNED NOT NULL,`source_value` VARCHAR(16) NOT NULL,'
            . '`updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),PRIMARY KEY (`id`)) '
            . 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ) === false) {
        finalSourceRaceFail('final_source_table_create_' . $db->errno);
    }
    $repository->execute(
        'INSERT INTO `' . $sourceTable . '` (`id`,`source_value`) VALUES (1,?)',
        ['OLD'],
    );

    $heritageId = '10000000-0000-4000-8000-000000000001';
    $livingId = '10000000-0000-4000-8000-000000000002';
    $photos = [
        new \ClassIdentity\Gateway\GatewayPhotoCandidate(
            $heritageId,
            'HERITAGE',
            'ACTIVE',
            'ACTIVE',
            '旧投影历史照片',
            '2023-10-18',
            ['班级档案'],
            '旧投影历史照片',
            900001,
            'DAY',
            'ARCHIVE_CONFIRMED',
            null,
            [101],
        ),
        new \ClassIdentity\Gateway\GatewayPhotoCandidate(
            $livingId,
            'LIVING',
            'ACTIVE',
            'ACTIVE',
            '旧投影毕业动态',
            null,
            ['后来'],
            '旧投影毕业动态',
            900002,
            'UNKNOWN',
            'UNKNOWN',
            null,
            [102],
        ),
    ];
    $aggregatePayloads = [
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 2, 'groups' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => [
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 1, 'groups' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS => ['total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => ['available' => true, 'total' => 0, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
    ];
    $rebuild = static function (
        \ClassIdentity\Gateway\ReadProjectionStore $projectionStore,
        array $candidates,
        array $payloads,
    ) use ($allAggregateKinds): void {
        $photoToken = $projectionStore->beginPhotoCatalogBuild();
        $projectionStore->rebuildPhotos($candidates, false, $photoToken);
        $aggregateToken = $projectionStore->beginAggregateBuild($allAggregateKinds);
        $projectionStore->rebuildAggregates($payloads, $allAggregateKinds, $aggregateToken);
    };

    $rebuild($store, $photos, $aggregatePayloads);
    $assertStates($states($store), 'ACTIVE');
    ++$assertions;

    // PREPARED commits before an external/MyISAM phase. A concurrent builder
    // may therefore publish a valid snapshot of the still-old source.
    \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
        $repository,
        $allAggregateKinds,
        'TEST_EARLY_PREPARE',
    );
    $assertStates($states($store), 'STALE', 'TEST_EARLY_PREPARE');
    ++$assertions;
    $rebuild($store, $photos, $aggregatePayloads);
    $assertStates($states($store), 'ACTIVE');
    $sourceBefore = $repository->fetchOne(
        'SELECT `source_value` FROM `' . $sourceTable . '` WHERE `id`=1 LIMIT 1',
    );
    if (($sourceBefore['source_value'] ?? null) !== 'OLD') {
        finalSourceRaceFail('early_recovery_changed_source');
    }
    $assertions += 2;

    // Fault injection: final invalidation and source mutation are one InnoDB
    // commit. A failed source statement must retain both the old value and the
    // recovered ACTIVE projection byte-for-byte.
    $projectionTable = '`' . $repository->table('read_projection') . '`';
    $beforeFault = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,"
            . "HEX(`generation`) AS `generation`,`item_count`,`payload_json`,"
            . "HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` "
            . "FROM {$projectionTable} ORDER BY `projection_key`",
    );
    if ($db->query(
        'CREATE TRIGGER `' . $faultTrigger . '` BEFORE UPDATE ON `' . $sourceTable . '` FOR EACH ROW '
            . "BEGIN IF NEW.`source_value`='FAIL' THEN SIGNAL SQLSTATE '45000' "
            . "SET MESSAGE_TEXT='class_archive_final_source_fault'; END IF; END",
    ) === false) {
        finalSourceRaceFail('final_source_fault_trigger_create_' . $db->errno);
    }
    try {
        $repository->transaction(function (\ClassIdentity\Repository $tx) use ($allAggregateKinds, $sourceTable): void {
            \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
                $tx,
                $allAggregateKinds,
                'TEST_FINAL_SOURCE_FAULT',
            );
            $tx->execute(
                'UPDATE `' . $sourceTable . '` SET `source_value`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `id`=1',
                ['FAIL'],
            );
        });
        finalSourceRaceFail('final_source_fault_not_raised');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_identity_query_execute_failed_1644') {
            throw $error;
        }
    }
    if ($db->query('DROP TRIGGER IF EXISTS `' . $faultTrigger . '`') === false) {
        finalSourceRaceFail('final_source_fault_trigger_drop_' . $db->errno);
    }
    $afterFault = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`source_revision`) AS `source_revision`,"
            . "HEX(`generation`) AS `generation`,`item_count`,`payload_json`,"
            . "HEX(`payload_digest`) AS `payload_digest`,HEX(`dependency_revision`) AS `dependency_revision`,"
            . "`invalidated_reason`,`built_at`,`invalidated_at`,`updated_at` "
            . "FROM {$projectionTable} ORDER BY `projection_key`",
    );
    $sourceAfterFault = $repository->fetchOne(
        'SELECT `source_value` FROM `' . $sourceTable . '` WHERE `id`=1 LIMIT 1',
    );
    if ($afterFault !== $beforeFault || ($sourceAfterFault['source_value'] ?? null) !== 'OLD') {
        finalSourceRaceFail('final_source_fault_not_atomic');
    }
    $assertions += 2;

    // The builder starts after early recovery but before the true source
    // commit. Successful finalization must invalidate again and permanently
    // fence this token from publication.
    $preFinalizeToken = $store->beginPhotoCatalogBuild();
    $repository->transaction(function (\ClassIdentity\Repository $tx) use ($allAggregateKinds, $sourceTable): void {
        \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
            $tx,
            $allAggregateKinds,
            'TEST_FINAL_SOURCE_COMMIT',
        );
        $updated = $tx->execute(
            'UPDATE `' . $sourceTable . '` SET `source_value`=?,`updated_at`=UTC_TIMESTAMP(6) WHERE `id`=1',
            ['NEW'],
        );
        if ($updated !== 1) {
            finalSourceRaceFail('final_source_not_updated');
        }
    });
    $sourceAfterCommit = $repository->fetchOne(
        'SELECT `source_value` FROM `' . $sourceTable . '` WHERE `id`=1 LIMIT 1',
    );
    if (($sourceAfterCommit['source_value'] ?? null) !== 'NEW') {
        finalSourceRaceFail('final_source_not_committed');
    }
    $assertStates($states($store), 'STALE', 'TEST_FINAL_SOURCE_COMMIT');
    $staleSnapshot = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`generation`) AS `generation`,`invalidated_reason` "
            . "FROM {$projectionTable} ORDER BY `projection_key`",
    );
    try {
        $store->rebuildPhotos($photos, false, $preFinalizeToken);
        finalSourceRaceFail('pre_finalize_token_published');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_source_epoch_changed') {
            throw $error;
        }
    }
    $afterRejectedPublish = $repository->fetchAll(
        "SELECT `projection_key`,`state`,HEX(`generation`) AS `generation`,`invalidated_reason` "
            . "FROM {$projectionTable} ORDER BY `projection_key`",
    );
    if ($afterRejectedPublish !== $staleSnapshot) {
        finalSourceRaceFail('rejected_token_mutated_projection');
    }
    try {
        $store->photos();
        finalSourceRaceFail('final_source_stale_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_projection_unavailable') {
            throw $error;
        }
    }
    $assertions += 5;

    $rebuild($store, $photos, $aggregatePayloads);
    $assertStates($states($store), 'ACTIVE');
    if (count($store->photos()) !== 2) {
        finalSourceRaceFail('fresh_token_recovery_failed');
    }
    $assertions += 2;

    // Reader-side deterministic invalidation injection. ClassIdentity-owned
    // source writes do not rotate the native Piwigo sentinel, so every catalog
    // read must recheck the exact ACTIVE generation after decoding its rows.
    foreach ([
        'photos' => [],
        'photo' => [$heritageId],
        'photosByIds' => [[$heritageId]],
    ] as $method => $arguments) {
        $checkpoint = \ClassIdentity\Gateway\ReadProjectionStore::PHOTO_CATALOG . ':' . $method;
        $injected = false;
        $raceStore = new \ClassIdentity\Gateway\ReadProjectionStore(
            $repository,
            static function (string $actual) use (&$injected, $checkpoint, $repository, $allAggregateKinds): void {
                if (!$injected && hash_equals($checkpoint, $actual)) {
                    $injected = true;
                    \ClassIdentity\ProjectionMutationBoundary::invalidatePhotos(
                        $repository,
                        $allAggregateKinds,
                        'TEST_READER_CATALOG_INVALIDATION',
                    );
                }
            },
        );
        try {
            $raceStore->{$method}(...$arguments);
            finalSourceRaceFail('reader_catalog_race_not_closed_' . $method);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'class_archive_read_projection_unavailable') {
                throw $error;
            }
        }
        if (!$injected) {
            finalSourceRaceFail('reader_catalog_race_not_injected_' . $method);
        }
        $rebuild($store, $photos, $aggregatePayloads);
        ++$assertions;
    }

    // Aggregate-only writes (for example person visibility or Spotlight)
    // leave PHOTO_CATALOG active. The selected kind therefore needs its own
    // final binding check rather than relying only on the catalog sentinel.
    $aggregateInjected = false;
    $aggregateRaceStore = new \ClassIdentity\Gateway\ReadProjectionStore(
        $repository,
        static function (string $checkpoint) use (&$aggregateInjected, $repository): void {
            if (!$aggregateInjected && $checkpoint === 'AGGREGATE:PEOPLE') {
                $aggregateInjected = true;
                \ClassIdentity\ProjectionMutationBoundary::invalidateAggregates(
                    $repository,
                    [\ClassIdentity\Gateway\ReadProjectionStore::PEOPLE],
                    'TEST_READER_AGGREGATE_INVALIDATION',
                );
            }
        },
    );
    try {
        $aggregateRaceStore->aggregate(
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE,
        );
        finalSourceRaceFail('reader_aggregate_race_not_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') {
            throw $error;
        }
    }
    if (!$aggregateInjected) {
        finalSourceRaceFail('reader_aggregate_race_not_injected');
    }
    $rebuild($store, $photos, $aggregatePayloads);
    if (preg_match('/\A[a-f0-9]{64}\z/D', $store->presentationEpoch(
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE,
    )) !== 1) {
        finalSourceRaceFail('presentation_epoch_invalid');
    }
    $assertions += 2;

    // Static placement guards ensure both real source services implement the
    // exact transaction shape exercised above, not merely the shared helper.
    $bulk = file_get_contents($root . '/plugins/ClassIdentity/src/BulkArchiveService.php');
    $canonical = file_get_contents($root . '/plugins/ClassIdentity/src/CanonicalPhotoService.php');
    if (!is_string($bulk) || !is_string($canonical)) {
        finalSourceRaceFail('service_source_unreadable');
    }
    $bulkMethodStart = strpos($bulk, 'public function apply(');
    $bulkMethodEnd = strpos($bulk, 'public function journalStatus(', is_int($bulkMethodStart) ? $bulkMethodStart : 0);
    $bulkMethod = is_int($bulkMethodStart) && is_int($bulkMethodEnd)
        ? substr($bulk, $bulkMethodStart, $bulkMethodEnd - $bulkMethodStart)
        : '';
    $bulkFinalize = strpos($bulkMethod, "'ARCHIVE_BULK_FINALIZE'");
    $bulkSourceWrite = strpos($bulkMethod, '$this->updateArchiveRow');
    $bulkTransaction = is_int($bulkFinalize)
        ? strrpos(substr($bulkMethod, 0, $bulkFinalize), '$this->repository->transaction(')
        : false;
    $bulkTransactionEnd = is_int($bulkTransaction)
        ? strpos($bulkMethod, "\n            });", $bulkTransaction)
        : false;
    if (!is_int($bulkTransaction) || !is_int($bulkFinalize) || !is_int($bulkSourceWrite)
        || !is_int($bulkTransactionEnd)
        || !($bulkTransaction < $bulkFinalize && $bulkFinalize < $bulkSourceWrite && $bulkSourceWrite < $bulkTransactionEnd)
    ) {
        finalSourceRaceFail('bulk_final_invalidation_not_before_source');
    }
    $canonicalMethodStart = strpos($canonical, 'public function consolidateExact(');
    $canonicalMethodEnd = strpos($canonical, 'public function revertConsolidation(', is_int($canonicalMethodStart) ? $canonicalMethodStart : 0);
    $canonicalMethod = is_int($canonicalMethodStart) && is_int($canonicalMethodEnd)
        ? substr($canonical, $canonicalMethodStart, $canonicalMethodEnd - $canonicalMethodStart)
        : '';
    $canonicalFinalize = strpos($canonicalMethod, "'CANONICAL_CONSOLIDATE_FINALIZE'");
    $canonicalSourceWrite = strpos($canonicalMethod, '$locked = $repository->fetchOne');
    $canonicalTransaction = is_int($canonicalFinalize)
        ? strrpos(substr($canonicalMethod, 0, $canonicalFinalize), '$this->repository->transaction(')
        : false;
    $canonicalTransactionEnd = is_int($canonicalTransaction)
        ? strpos($canonicalMethod, "\n            });", $canonicalTransaction)
        : false;
    if (!is_int($canonicalTransaction) || !is_int($canonicalFinalize) || !is_int($canonicalSourceWrite)
        || !is_int($canonicalTransactionEnd)
        || !($canonicalTransaction < $canonicalFinalize
            && $canonicalFinalize < $canonicalSourceWrite
            && $canonicalSourceWrite < $canonicalTransactionEnd)
    ) {
        finalSourceRaceFail('canonical_final_invalidation_not_before_source');
    }
    $assertions += 2;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(
        STDERR,
        'CLASS_ARCHIVE_FINAL_SOURCE_RACE=FAIL reason='
            . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n",
    );
} finally {
    $db->query('DROP TRIGGER IF EXISTS `' . $faultTrigger . '`');
    $db->query('DROP TABLE IF EXISTS `' . $sourceTable . '`');
    foreach (['read_photo', 'read_projection', 'native_source_epoch'] as $suffix) {
        $name = $basePrefix . 'class_identity_' . $suffix;
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) === 1) {
            $db->query('DROP TABLE IF EXISTS `' . $name . '`');
        }
    }
    if ($createdNative !== []) {
        try {
            classIdentityDropNativeProjectionFixture($db, $createdNative);
        } catch (Throwable $cleanupError) {
            fwrite(
                STDERR,
                'CLASS_ARCHIVE_FINAL_SOURCE_RACE_CLEANUP=FAIL reason='
                    . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n",
            );
            $exit = 1;
        }
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(STDOUT, "CLASS_ARCHIVE_FINAL_SOURCE_RACE=PASS assertions={$assertions} run={$run}\n");
}
exit($exit);
