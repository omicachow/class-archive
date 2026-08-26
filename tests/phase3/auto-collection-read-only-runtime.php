<?php

declare(strict_types=1);

/**
 * Real MariaDB proof for the bounded Memory read boundary.  This fixture
 * creates a valid durable FULL/HERITAGE projection, calls the actual Gateway
 * home + memories reads, and proves the same DB session executed no INSERT,
 * UPDATE, DELETE or REPLACE after its read-only checkpoint.  It deliberately
 * does not create AutoCollection tables: a browser read must not need the
 * build-side persistence domain at all.
 */

function autoCollectionReadOnlyFail(string $message): never
{
    throw new RuntimeException($message);
}

function autoCollectionReadOnlyIdent(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $name) !== 1) {
        autoCollectionReadOnlyFail('auto_collection_read_only_identifier_invalid');
    }
    return '`' . $name . '`';
}

function autoCollectionReadOnlyExec(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        autoCollectionReadOnlyFail('auto_collection_read_only_query_failed_' . $db->errno);
    }
}

/** @return array<string,string> */
function autoCollectionReadOnlyWriteCounters(mysqli $db): array
{
    $result = $db->query("SHOW SESSION STATUS WHERE `Variable_name` IN ('Com_insert','Com_update','Com_delete','Com_replace')");
    if (!$result instanceof mysqli_result) {
        autoCollectionReadOnlyFail('auto_collection_read_only_status_query_failed_' . $db->errno);
    }
    $counters = [];
    while (($row = $result->fetch_assoc()) !== null) {
        $name = $row['Variable_name'] ?? null;
        $value = $row['Value'] ?? null;
        if (!is_string($name) || !is_string($value)) {
            $result->free();
            autoCollectionReadOnlyFail('auto_collection_read_only_status_shape_invalid');
        }
        $counters[$name] = $value;
    }
    $result->free();
    foreach (['Com_insert', 'Com_update', 'Com_delete', 'Com_replace'] as $name) {
        if (!isset($counters[$name]) || preg_match('/\A\d+\z/D', $counters[$name]) !== 1) {
            autoCollectionReadOnlyFail('auto_collection_read_only_status_missing');
        }
    }
    ksort($counters, SORT_STRING);
    return $counters;
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "AUTO_COLLECTION_READ_ONLY_RUNTIME=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "AUTO_COLLECTION_READ_ONLY_RUNTIME=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "AUTO_COLLECTION_READ_ONLY_RUNTIME=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "AUTO_COLLECTION_READ_ONLY_RUNTIME=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/Access.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';

final class AutoCollectionReadOnlyIdentityAdapter implements \ClassIdentity\Gateway\IdentityAdapter
{
    public function __construct(private readonly \ClassIdentity\Gateway\GatewayPrincipal $principal)
    {
    }

    public function currentPrincipal(): ?\ClassIdentity\Gateway\GatewayPrincipal
    {
        return $this->principal;
    }
}

final class AutoCollectionReadOnlyPiwigoAdapter implements \ClassIdentity\Gateway\PiwigoAdapter
{
    public function photoCandidates(): array
    {
        return [];
    }
}

final class AutoCollectionReadOnlyImmichAdapter implements \ClassIdentity\Gateway\ImmichAdapter
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

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_auto_mem_read_' . $run . '_';
$ci = $basePrefix . 'class_identity_';
$projectionTable = $ci . 'read_projection';
$nativeEpochTable = $ci . 'native_source_epoch';
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$assertions = 0;
$exit = 0;
$assert = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        autoCollectionReadOnlyFail($message);
    }
};

try {
    autoCollectionReadOnlyExec(
        $db,
        'CREATE TABLE ' . autoCollectionReadOnlyIdent($projectionTable) . ' ('
            . '`projection_key` VARCHAR(32) NOT NULL,`state` VARCHAR(16) NOT NULL,'
            . '`source_revision` BINARY(32) NULL,`generation` BINARY(16) NOT NULL,'
            . '`native_source_generation` BINARY(16) NULL,`item_count` INT UNSIGNED NOT NULL DEFAULT 0,'
            . '`payload_json` JSON NULL,`payload_digest` BINARY(32) NULL,`dependency_revision` BINARY(32) NULL,'
            . 'PRIMARY KEY (`projection_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );
    autoCollectionReadOnlyExec(
        $db,
        'CREATE TABLE ' . autoCollectionReadOnlyIdent($nativeEpochTable) . ' ('
            . '`source_key` VARCHAR(32) NOT NULL,`generation` BINARY(16) NOT NULL,'
            . 'PRIMARY KEY (`source_key`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
    );

    $catalogGeneration = random_bytes(16);
    $catalogRevision = random_bytes(32);
    $nativeEpoch = random_bytes(16);
    $repository->execute(
        'INSERT INTO ' . autoCollectionReadOnlyIdent($nativeEpochTable) . ' (`source_key`,`generation`) VALUES (?,?)',
        ['PIWIGO_NATIVE', $nativeEpoch],
    );
    $repository->execute(
        'INSERT INTO ' . autoCollectionReadOnlyIdent($projectionTable)
            . ' (`projection_key`,`state`,`source_revision`,`generation`,`native_source_generation`,`item_count`) '
            . "VALUES ('PHOTO_CATALOG','ACTIVE',?,?,?,?)",
        [$catalogRevision, $catalogGeneration, $nativeEpoch, 2],
    );

    $heritagePhotoId = '10000000-0000-4000-8000-000000000001';
    $livingPhotoId = '10000000-0000-4000-8000-000000000002';
    $internalReason = 'MEMORY:' . str_repeat('A', 56);
    $fullMemory = [
        'available' => true,
        'total' => 1,
        'items' => [[
            'label' => '内部完整回忆',
            'subtitle' => '仅构建阶段元数据',
            'kind' => 'EVENT',
            'photo_count' => 2,
            'cover_photo_id' => $livingPhotoId,
            'photo_ids' => [$heritagePhotoId, $livingPhotoId],
            'source_reason' => $internalReason,
            'projection_revision' => str_repeat('b', 64),
            'archive_date' => null,
            'date_precision' => 'EVENT_ONLY',
        ]],
    ];
    $heritageMemory = [
        'available' => true,
        'total' => 1,
        'items' => [[
            'label' => '仅历史回忆',
            'subtitle' => '仅构建阶段元数据',
            'kind' => 'EVENT',
            'photo_count' => 1,
            'cover_photo_id' => $heritagePhotoId,
            'photo_ids' => [$heritagePhotoId],
            'source_reason' => $internalReason,
            'projection_revision' => str_repeat('c', 64),
            'archive_date' => null,
            'date_precision' => 'EVENT_ONLY',
        ]],
    ];
    $aggregateKinds = [
        \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE,
        \ClassIdentity\Gateway\ReadProjectionStore::ALBUMS,
        \ClassIdentity\Gateway\ReadProjectionStore::PEOPLE,
        \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES,
        \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
    ];
    foreach ($aggregateKinds as $kind) {
        $generation = random_bytes(16);
        $full = match ($kind) {
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 2, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => $fullMemory,
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
            default => ['total' => 0, 'items' => []],
        };
        $heritage = match ($kind) {
            \ClassIdentity\Gateway\ReadProjectionStore::TIMELINE => ['total' => 1, 'items' => []],
            \ClassIdentity\Gateway\ReadProjectionStore::MEMORIES => $heritageMemory,
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
            default => ['total' => 0, 'items' => []],
        };
        $payload = [
            '_projection' => [
                'version' => 3,
                'kind' => $kind,
                'catalog_generation' => bin2hex($catalogGeneration),
                'catalog_revision' => bin2hex($catalogRevision),
                'kind_epoch' => bin2hex($generation),
            ],
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => $full,
            \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => $heritage,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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
        $repository->execute(
            'INSERT INTO ' . autoCollectionReadOnlyIdent($projectionTable)
                . ' (`projection_key`,`state`,`source_revision`,`generation`,`native_source_generation`,`item_count`,'
                . '`payload_json`,`payload_digest`,`dependency_revision`) VALUES (?,?,?, ?,NULL,?,?,?,?)',
            [$kind, 'ACTIVE', $sourceRevision, $generation, 1, $payloadJson, $payloadDigest, $dependencyRevision],
        );
    }

    $store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
    $piwigo = new AutoCollectionReadOnlyPiwigoAdapter();
    $immich = new AutoCollectionReadOnlyImmichAdapter();
    $fullGateway = new \ClassIdentity\Gateway\GatewayService(
        new AutoCollectionReadOnlyIdentityAdapter(new \ClassIdentity\Gateway\GatewayPrincipal(\ClassIdentity\Access::ROLE_CLASSMATE)),
        $piwigo,
        $immich,
        new \ClassIdentity\Gateway\GatewayPolicy(),
        readProjection: $store,
    );
    $heritageGateway = new \ClassIdentity\Gateway\GatewayService(
        new AutoCollectionReadOnlyIdentityAdapter(new \ClassIdentity\Gateway\GatewayPrincipal(\ClassIdentity\Access::ROLE_FAMILY)),
        $piwigo,
        $immich,
        new \ClassIdentity\Gateway\GatewayPolicy(),
        readProjection: $store,
    );

    $beforeWrites = autoCollectionReadOnlyWriteCounters($db);
    $fullHome = $fullGateway->home();
    $fullMemories = $fullGateway->memories();
    $heritageHome = $heritageGateway->home();
    $heritageMemories = $heritageGateway->memories();
    $afterWrites = autoCollectionReadOnlyWriteCounters($db);

    $assert($beforeWrites === $afterWrites, 'gateway_reads_changed_session_write_counter');
    $assert(($fullHome['allPhotos']['total'] ?? null) === 2 && ($heritageHome['allPhotos']['total'] ?? null) === 1, 'role_scoped_timeline_total_invalid');
    $assert(($fullHome['memories']['items'][0]['photo_count'] ?? null) === 2, 'full_home_memory_count_invalid');
    $assert(($heritageHome['memories']['items'][0]['photo_count'] ?? null) === 1, 'heritage_home_memory_count_leaked');
    $assert(($fullMemories['items'][0]['cover_photo_id'] ?? null) === $livingPhotoId, 'full_memory_cover_invalid');
    $assert(($heritageMemories['items'][0]['cover_photo_id'] ?? null) === $heritagePhotoId, 'heritage_memory_cover_leaked');
    $publicJson = json_encode([$fullHome, $fullMemories, $heritageHome, $heritageMemories], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    foreach (['photo_ids', 'source_reason', 'projection_revision', $internalReason, $livingPhotoId] as $internal) {
        // The full-classmate cover legitimately contains $livingPhotoId; only
        // require its absence in the HERITAGE payload below.
        if ($internal === $livingPhotoId) {
            continue;
        }
        $assert(!str_contains($publicJson, $internal), 'internal_memory_field_leaked_' . hash('sha256', $internal));
    }
    $heritageJson = json_encode([$heritageHome, $heritageMemories], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $assert(!str_contains($heritageJson, $livingPhotoId), 'heritage_memory_living_id_leaked');

    fwrite(STDOUT, "AUTO_COLLECTION_READ_ONLY_RUNTIME=PASS assertions={$assertions} run={$run}\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'AUTO_COLLECTION_READ_ONLY_RUNTIME=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    foreach ([$projectionTable, $nativeEpochTable] as $table) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $table) === 1) {
            $db->query('DROP TABLE IF EXISTS ' . autoCollectionReadOnlyIdent($table));
        }
    }
    $db->close();
}

exit($exit);
