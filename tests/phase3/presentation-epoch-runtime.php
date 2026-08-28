<?php

declare(strict_types=1);

function presentationEpochRuntimeFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "PRESENTATION_EPOCH_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "PRESENTATION_EPOCH_RUNTIME=FAIL reason=nginx_user_required\n");
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
    fwrite(STDERR, "PRESENTATION_EPOCH_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_epoch_' . $run . '_';
$classPrefix = $basePrefix . 'class_identity_';
$projectionTable = $classPrefix . 'read_projection';
$nativeEpochTable = $classPrefix . 'native_source_epoch';
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$assertions = 0;
$exit = 0;

$aggregateKinds = [
    \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
    \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
    \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
    \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
    \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
];
$expectFailure = static function (callable $callback, string $expected, string $label) use (&$assertions): void {
    try {
        $callback();
        presentationEpochRuntimeFail($label . '_not_rejected');
    } catch (Throwable $error) {
        if ($error->getMessage() !== $expected) {
            throw $error;
        }
    }
    ++$assertions;
};

try {
    if ($db->query(
        "CREATE TABLE `{$projectionTable}` ("
            . '`projection_key` VARCHAR(32) NOT NULL,`state` VARCHAR(16) NOT NULL,'
            . '`source_revision` BINARY(32) NULL,`generation` BINARY(16) NOT NULL,'
            . '`native_source_generation` BINARY(16) NULL,`item_count` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`payload_json` JSON NULL,`payload_digest` BINARY(32) NULL,`dependency_revision` BINARY(32) NULL,'
            . 'PRIMARY KEY (`projection_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ) === false) {
        presentationEpochRuntimeFail('projection_table_create_' . $db->errno);
    }
    if ($db->query(
        "CREATE TABLE `{$nativeEpochTable}` ("
            . '`source_key` VARCHAR(32) NOT NULL,`generation` BINARY(16) NOT NULL,'
            . 'PRIMARY KEY (`source_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    ) === false) {
        presentationEpochRuntimeFail('native_epoch_table_create_' . $db->errno);
    }

    $catalogGeneration = random_bytes(16);
    $catalogRevision = random_bytes(32);
    $nativeEpoch = random_bytes(16);
    $repository->execute(
        "INSERT INTO `{$nativeEpochTable}` (`source_key`,`generation`) VALUES ('PIWIGO_NATIVE',?)",
        [$nativeEpoch],
    );
    $repository->execute(
        "INSERT INTO `{$projectionTable}` "
            . '(`projection_key`,`state`,`source_revision`,`generation`,`native_source_generation`,`item_count`) '
            . "VALUES ('PHOTO_CATALOG','ACTIVE',?,?,?,?)",
        [$catalogRevision, $catalogGeneration, $nativeEpoch, 7],
    );

    $metadata = [];
    foreach ($aggregateKinds as $index => $kind) {
        $generation = random_bytes(16);
        $payloadJson = json_encode([
            '_projection' => [
                'version' => 3,
                'kind' => $kind,
                'catalog_generation' => bin2hex($catalogGeneration),
                'catalog_revision' => bin2hex($catalogRevision),
                'kind_epoch' => bin2hex($generation),
            ],
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => ['kind' => $kind, 'scope' => 'full'],
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => ['kind' => $kind, 'scope' => 'heritage'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $payloadDigest = hash('sha256', $payloadJson, true);
        $dependencyRevision = hash(
            'sha256',
            "class-archive-aggregate-contract\0"
                . "3\0{$kind}\0"
                . $catalogGeneration
                . $catalogRevision
                . $generation,
            true,
        );
        $sourceRevision = hash('sha256', $dependencyRevision . $payloadDigest, true);
        $itemCount = $index + 1;
        $repository->execute(
            "INSERT INTO `{$projectionTable}` "
                . '(`projection_key`,`state`,`source_revision`,`generation`,`native_source_generation`,`item_count`,'
                . '`payload_json`,`payload_digest`,`dependency_revision`) '
                . "VALUES (?,'ACTIVE',?,?,NULL,?,?,?,?)",
            [$kind, $sourceRevision, $generation, $itemCount, $payloadJson, $payloadDigest, $dependencyRevision],
        );
        $metadata[$kind] = [
            'source_revision' => $sourceRevision,
            'generation' => $generation,
            'payload_digest' => $payloadDigest,
            'dependency_revision' => $dependencyRevision,
            'item_count' => $itemCount,
        ];
    }

    $expectedEpoch = static function (string $scope) use (
        $catalogRevision,
        $catalogGeneration,
        $nativeEpoch,
        $aggregateKinds,
        $metadata,
    ): string {
        $material = [
            'version' => 1,
            'scope' => $scope,
            'catalog' => [
                'source_revision' => bin2hex($catalogRevision),
                'generation' => bin2hex($catalogGeneration),
                'native_source_generation' => bin2hex($nativeEpoch),
                'item_count' => 7,
            ],
            'aggregates' => [],
        ];
        foreach ($aggregateKinds as $kind) {
            $material['aggregates'][$kind] = [
                'source_revision' => bin2hex($metadata[$kind]['source_revision']),
                'generation' => bin2hex($metadata[$kind]['generation']),
                'payload_digest' => bin2hex($metadata[$kind]['payload_digest']),
                'dependency_revision' => bin2hex($metadata[$kind]['dependency_revision']),
                'item_count' => $metadata[$kind]['item_count'],
            ];
        }
        return hash('sha256', json_encode($material, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    };

    $checkpoints = [];
    $store = new \ClassIdentity\Gateway\ReadProjectionStore(
        $repository,
        static function (string $checkpoint) use (&$checkpoints): void {
            $checkpoints[] = $checkpoint;
        },
    );
    $fullEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL);
    if (!hash_equals($expectedEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL), $fullEpoch)) {
        presentationEpochRuntimeFail('full_epoch_material_changed');
    }
    ++$assertions;
    $expectedCheckpoints = array_map(static fn (string $kind): string => 'AGGREGATE:' . $kind, $aggregateKinds);
    if ($checkpoints !== $expectedCheckpoints) {
        presentationEpochRuntimeFail('race_checkpoints_changed');
    }
    ++$assertions;
    $heritageEpoch = $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE);
    if (!hash_equals($expectedEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE), $heritageEpoch)
        || hash_equals($fullEpoch, $heritageEpoch)
    ) {
        presentationEpochRuntimeFail('scope_epoch_material_changed');
    }
    ++$assertions;

    $validPayload = $store->aggregate(
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL,
    );
    if (($validPayload['kind'] ?? null) !== \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE
        || ($validPayload['scope'] ?? null) !== 'full'
    ) {
        presentationEpochRuntimeFail('valid_payload_control_failed');
    }
    ++$assertions;

    // Corrupt every stored payload without changing any metadata. aggregate()
    // must still reject it, while the epoch remains metadata-only and stable.
    $repository->execute(
        "UPDATE `{$projectionTable}` SET `payload_json`='{\"poison\":true}' WHERE `projection_key`<>'PHOTO_CATALOG'",
    );
    $expectFailure(
        static fn () => $store->aggregate(
            \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL,
        ),
        'class_archive_read_aggregate_payload_invalid',
        'poison_payload_control',
    );
    if (!hash_equals($fullEpoch, $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL))) {
        presentationEpochRuntimeFail('poison_payload_changed_epoch');
    }
    ++$assertions;

    foreach ($aggregateKinds as $kind) {
        $repository->execute(
            "UPDATE `{$projectionTable}` SET `payload_digest`=? WHERE `projection_key`=?",
            [random_bytes(32), $kind],
        );
        $expectFailure(
            static fn () => $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL),
            'class_archive_read_aggregate_digest_mismatch',
            'metadata_digest_' . strtolower($kind),
        );
        $repository->execute(
            "UPDATE `{$projectionTable}` SET `payload_digest`=? WHERE `projection_key`=?",
            [$metadata[$kind]['payload_digest'], $kind],
        );

        $forgedDependency = random_bytes(32);
        $forgedSourceRevision = hash('sha256', $forgedDependency . $metadata[$kind]['payload_digest'], true);
        $repository->execute(
            "UPDATE `{$projectionTable}` SET `source_revision`=?,`dependency_revision`=? WHERE `projection_key`=?",
            [$forgedSourceRevision, $forgedDependency, $kind],
        );
        $expectFailure(
            static fn () => $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL),
            'class_archive_read_aggregate_digest_mismatch',
            'metadata_dependency_' . strtolower($kind),
        );
        $repository->execute(
            "UPDATE `{$projectionTable}` SET `source_revision`=?,`dependency_revision`=? WHERE `projection_key`=?",
            [$metadata[$kind]['source_revision'], $metadata[$kind]['dependency_revision'], $kind],
        );
    }

    $repository->execute("UPDATE `{$projectionTable}` SET `state`='STALE' WHERE `projection_key`='PHOTO_CATALOG'");
    $expectFailure(
        static fn () => $store->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL),
        'class_archive_read_projection_unavailable',
        'catalog_metadata_state',
    );
    $repository->execute("UPDATE `{$projectionTable}` SET `state`='ACTIVE' WHERE `projection_key`='PHOTO_CATALOG'");

    $aggregateRaceInjected = false;
    $aggregateRaceStore = new \ClassIdentity\Gateway\ReadProjectionStore(
        $repository,
        static function (string $checkpoint) use (&$aggregateRaceInjected, $repository, $projectionTable): void {
            if (!$aggregateRaceInjected && $checkpoint === 'AGGREGATE:PEOPLE') {
                $aggregateRaceInjected = true;
                $repository->execute(
                    "UPDATE `{$projectionTable}` SET `state`='STALE' WHERE `projection_key`='PEOPLE'",
                );
            }
        },
    );
    $expectFailure(
        static fn () => $aggregateRaceStore->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL),
        'class_archive_read_aggregate_unavailable',
        'aggregate_final_race',
    );
    if (!$aggregateRaceInjected) {
        presentationEpochRuntimeFail('aggregate_final_race_not_injected');
    }
    ++$assertions;
    $repository->execute("UPDATE `{$projectionTable}` SET `state`='ACTIVE' WHERE `projection_key`='PEOPLE'");

    $catalogRaceInjected = false;
    $catalogRaceStore = new \ClassIdentity\Gateway\ReadProjectionStore(
        $repository,
        static function (string $checkpoint) use (&$catalogRaceInjected, $repository, $projectionTable): void {
            if (!$catalogRaceInjected && $checkpoint === 'AGGREGATE:TIMELINE') {
                $catalogRaceInjected = true;
                $repository->execute(
                    "UPDATE `{$projectionTable}` SET `state`='STALE' WHERE `projection_key`='PHOTO_CATALOG'",
                );
            }
        },
    );
    $expectFailure(
        static fn () => $catalogRaceStore->presentationEpoch(\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL),
        'class_archive_read_projection_unavailable',
        'catalog_final_race',
    );
    if (!$catalogRaceInjected) {
        presentationEpochRuntimeFail('catalog_final_race_not_injected');
    }
    ++$assertions;
    $repository->execute("UPDATE `{$projectionTable}` SET `state`='ACTIVE' WHERE `projection_key`='PHOTO_CATALOG'");

    $expectFailure(
        static fn () => $store->presentationEpoch('UNKNOWN'),
        'class_archive_read_aggregate_scope_invalid',
        'invalid_scope',
    );
} catch (Throwable $error) {
    fwrite(STDERR, 'PRESENTATION_EPOCH_RUNTIME=FAIL reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ([$projectionTable, $nativeEpochTable] as $table) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) === 1) {
            $db->query("DROP TABLE IF EXISTS `{$table}`");
        }
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(STDOUT, 'PRESENTATION_EPOCH_RUNTIME=PASS assertions=' . $assertions . "\n");
}
exit($exit);
