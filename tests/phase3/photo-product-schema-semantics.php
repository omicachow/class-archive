<?php

declare(strict_types=1);

/**
 * Real MariaDB semantic and constraint gate for migration 8. Later additive
 * schema semantics are covered by collections-first-schema-semantics.php;
 * this fixture retains the exact v8 album digest so its isolated historic
 * reconstruction remains independently meaningful.
 *
 * The fixture reconstructs the exact v7 person table plus minimal referenced
 * keys under a random prefix, applies only migration 8, fingerprints every
 * productization table, exercises representative CHECK constraints, and drops
 * all disposable state in finally. Live ClassIdentity data is never changed.
 */

const PHOTO_PRODUCT_SCHEMA_SUFFIXES = [
    'batch_operation_item',
    'batch_operation',
    'photo_duplicate',
    'photo_source',
    'archive_image',
    'spotlight',
    'album',
    'person_photo_rule',
    'person_merge',
    'person',
    'photo',
    'principal',
    'identity',
];

const PHOTO_PRODUCT_DIGEST_SUFFIXES = [
    'person',
    'person_merge',
    'person_photo_rule',
    'album',
    'spotlight',
    'photo_source',
    'photo_duplicate',
    'batch_operation',
    'batch_operation_item',
];

const PHOTO_PRODUCT_V8_DIGESTS = [
    'album' => '5f3a5e5b67c9e6fd534faaf48f3d327cb5b090a5bce9aaaed6001a79021100b6',
];

function productSchemaFail(string $message): never
{
    throw new RuntimeException($message);
}

function productSchemaIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        productSchemaFail('photo_product_schema_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function productSchemaExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        productSchemaFail('photo_product_schema_query_failed_' . $db->errno);
    }
}

function productSchemaExpectRejected(mysqli $db, string $sql, string $label): void
{
    if ($db->query($sql) !== false) {
        productSchemaFail('photo_product_constraint_not_enforced_' . $label);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Schema.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Repository.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/CoreAdapter.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Access.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/DomainSupport.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/CanonicalPhotoService.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/BulkArchiveService.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';

final class ProductCanonicalIdentityAdapter implements \ClassIdentity\Gateway\IdentityAdapter
{
    public function currentPrincipal(): ?\ClassIdentity\Gateway\GatewayPrincipal
    {
        return new \ClassIdentity\Gateway\GatewayPrincipal(\ClassIdentity\Access::ROLE_FAMILY);
    }
}

final class ProductCanonicalPiwigoAdapter implements \ClassIdentity\Gateway\PiwigoAdapter
{
    /** @param list<\ClassIdentity\Gateway\GatewayPhotoCandidate> $photos */
    public function __construct(private readonly array $photos)
    {
    }

    public function photoCandidates(): array
    {
        return $this->photos;
    }
}

final class ProductCanonicalImmichAdapter implements \ClassIdentity\Gateway\ImmichAdapter
{
    /** @param list<string> $results */
    public function __construct(private readonly array $results)
    {
    }

    public function availability(): string
    {
        return 'AVAILABLE';
    }

    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        $members = array_values(array_filter($this->results, static fn(string $id): bool => isset($allowed[$id])));
        return $members === [] ? [] : [new \ClassIdentity\Gateway\GatewayPersonCandidate(
            '20000000-0000-4000-8000-000000000009',
            '别名人物',
            $members,
            $members[0],
        )];
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        $members = array_values(array_filter($this->results, static fn(string $id): bool => isset($allowed[$id])));
        return $members === [] ? [] : [new \ClassIdentity\Gateway\GatewayMemoryCandidate('别名回忆', $members)];
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($query);
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        return array_values(array_filter($this->results, static fn(string $id): bool => isset($allowed[$id])));
    }
}

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_product_sem_' . $run . '_';
$tablePrefix = $basePrefix . 'class_identity_';
$schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$lockPeer = null;
$exit = 0;
$assertions = 0;

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    if (!str_starts_with($version, '11.8.8-MariaDB')) {
        productSchemaFail('photo_product_schema_locked_mariadb_required');
    }

    $identity = productSchemaIdentifier($tablePrefix . 'identity');
    $principal = productSchemaIdentifier($tablePrefix . 'principal');
    $photo = productSchemaIdentifier($tablePrefix . 'photo');
    $person = productSchemaIdentifier($tablePrefix . 'person');
    $personIdentityFk = productSchemaIdentifier('fk_ci_product_person_identity_' . $run);

    productSchemaExecute($db, "CREATE TABLE {$identity} (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    productSchemaExecute($db, "CREATE TABLE {$principal} (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    productSchemaExecute($db, "CREATE TABLE {$photo} (`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    productSchemaExecute($db, <<<SQL
CREATE TABLE {$person} (
  `class_person_id` BINARY(16) NOT NULL,
  `immich_person_id` CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL,
  `display_name` VARCHAR(190) NULL,
  `classmate_identity_id` BIGINT UNSIGNED NULL,
  `source_kind` VARCHAR(24) NOT NULL DEFAULT 'IMMICH_CLUSTER',
  `state` VARCHAR(16) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  `updated_at` DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (`class_person_id`),
  UNIQUE KEY `uq_ci_person_immich` (`immich_person_id`),
  KEY `idx_ci_person_identity` (`classmate_identity_id`,`state`),
  KEY `idx_ci_person_state_updated` (`state`,`updated_at`),
  CONSTRAINT {$personIdentityFk} FOREIGN KEY (`classmate_identity_id`) REFERENCES {$identity} (`id`) ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT `chk_ci_person_source` CHECK (`source_kind` IN ('IMMICH_CLUSTER', 'MANUAL')),
  CONSTRAINT `chk_ci_person_state` CHECK (`state` IN ('ACTIVE', 'STALE', 'RETIRED'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $migration = new ReflectionMethod(ClassIdentity\Schema::class, 'migrationPhotoProductizationDomain');
    $migration->invoke($schema);
    $digest = new ReflectionMethod(ClassIdentity\Schema::class, 'semanticDigest');
    $expectedMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'expectedSemanticDigests');
    $expected = $expectedMethod->invoke(null);
    if (!is_array($expected)) {
        productSchemaFail('photo_product_expected_digest_contract_invalid');
    }
    $derivedDigests = [];
    foreach (PHOTO_PRODUCT_DIGEST_SUFFIXES as $suffix) {
        $actual = $digest->invoke($schema, $suffix);
        $expectedDigest = PHOTO_PRODUCT_V8_DIGESTS[$suffix] ?? ($expected[$suffix] ?? '');
        if (!is_string($actual) || (!$derive && !hash_equals((string) $expectedDigest, $actual))) {
            productSchemaFail('photo_product_digest_mismatch_' . $suffix);
        }
        $derivedDigests[$suffix] = $actual;
        ++$assertions;
    }
    if ($derive) {
        fwrite(STDOUT, 'CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=DERIVED album=' . ($derivedDigests['album'] ?? '')
            . ' photo_source=' . ($derivedDigests['photo_source'] ?? '') . ' run=' . $run . "\n");
        return;
    }

    productSchemaExecute($db, "INSERT INTO {$identity} (`id`) VALUES (1)");
    productSchemaExecute($db, "INSERT INTO {$principal} (`id`) VALUES (1)");
    productSchemaExecute($db, "INSERT INTO {$photo} (`class_photo_id`) VALUES (UNHEX('10000000000040008000000000000001')), (UNHEX('10000000000040008000000000000002'))");
    productSchemaExecute($db, "INSERT INTO {$person} (`class_person_id`,`source_kind`) VALUES (UNHEX('20000000000040008000000000000001'),'MANUAL'), (UNHEX('20000000000040008000000000000002'),'MANUAL')");

    $album = productSchemaIdentifier($tablePrefix . 'album');
    $spotlight = productSchemaIdentifier($tablePrefix . 'spotlight');
    $merge = productSchemaIdentifier($tablePrefix . 'person_merge');
    $duplicate = productSchemaIdentifier($tablePrefix . 'photo_duplicate');
    $batch = productSchemaIdentifier($tablePrefix . 'batch_operation');
    productSchemaExecute($db, "INSERT INTO {$album} (`class_album_id`,`piwigo_category_id`,`album_type`,`owner_principal_id`,`era`) VALUES (UNHEX('30000000000040008000000000000001'),900001,'OFFICIAL',NULL,'MIXED')");

    productSchemaExpectRejected($db, "INSERT INTO {$album} (`class_album_id`,`piwigo_category_id`,`album_type`,`owner_principal_id`,`era`) VALUES (UNHEX('30000000000040008000000000000002'),900002,'OFFICIAL',1,'HERITAGE')", 'official_album_owner');
    ++$assertions;
    productSchemaExpectRejected($db, "INSERT INTO {$spotlight} (`spotlight_id`,`owner_principal_id`,`class_album_id`,`starts_at`,`expires_at`) VALUES (UNHEX('40000000000040008000000000000001'),1,UNHEX('30000000000040008000000000000001'),'2026-01-01 00:00:00','2026-01-01 23:00:00')", 'spotlight_duration');
    ++$assertions;
    productSchemaExpectRejected($db, "INSERT INTO {$merge} (`merge_id`,`source_class_person_id`,`target_class_person_id`,`created_by_principal_id`,`reason`) VALUES (UNHEX('50000000000040008000000000000001'),UNHEX('20000000000040008000000000000001'),UNHEX('20000000000040008000000000000001'),1,'synthetic test')", 'person_merge_distinct');
    ++$assertions;
    productSchemaExpectRejected($db, "INSERT INTO {$duplicate} (`duplicate_id`,`left_class_photo_id`,`right_class_photo_id`,`relation_kind`,`state`,`canonical_class_photo_id`,`created_by_principal_id`,`reason`) VALUES (UNHEX('60000000000040008000000000000001'),UNHEX('10000000000040008000000000000001'),UNHEX('10000000000040008000000000000002'),'NEAR','CONSOLIDATED',UNHEX('10000000000040008000000000000001'),1,'synthetic test')", 'near_duplicate_consolidation');
    ++$assertions;
    productSchemaExpectRejected($db, "INSERT INTO {$batch} (`batch_id`,`actor_principal_id`,`operation_type`,`payload_digest`,`item_count`,`reason`) VALUES (UNHEX('70000000000040008000000000000001'),1,'ARCHIVE_BULK_UPDATE',UNHEX(SHA2('synthetic',256)),0,'synthetic test')", 'batch_nonempty');
    ++$assertions;

    // A consolidated exact duplicate remains physically intact while every
    // public projection resolves to one opaque canonical UUID. Alias archive
    // metadata and provenance remain reachable only through that canonical
    // projection; the alias media UUID itself is deliberately unknown.
    productSchemaExecute($db, "INSERT INTO {$duplicate} (`duplicate_id`,`left_class_photo_id`,`right_class_photo_id`,`relation_kind`,`state`,`canonical_class_photo_id`,`created_by_principal_id`,`reviewed_by_principal_id`,`reason`,`reviewed_at`) VALUES (UNHEX('60000000000040008000000000000002'),UNHEX('10000000000040008000000000000001'),UNHEX('10000000000040008000000000000002'),'EXACT','CONSOLIDATED',UNHEX('10000000000040008000000000000001'),1,1,'synthetic canonical projection',UTC_TIMESTAMP(6))");
    $source = productSchemaIdentifier($tablePrefix . 'photo_source');
    productSchemaExecute($db, "INSERT INTO {$source} (`class_photo_id`,`source_kind`,`provenance_code`,`source_checksum`,`byte_size`,`created_by_principal_id`) VALUES (UNHEX('10000000000040008000000000000001'),'MIGRATION','CANONICAL-SOURCE',UNHEX(SHA2('same-bytes',256)),101,1),(UNHEX('10000000000040008000000000000001'),'PRIVATE_FULL','FULL-SOURCE',UNHEX(SHA2('same-bytes',256)),101,1),(UNHEX('10000000000040008000000000000002'),'PIWIGO_IMPORT','ALIAS-SOURCE',UNHEX(SHA2('same-bytes',256)),101,1)");
    $archiveImage = productSchemaIdentifier($tablePrefix . 'archive_image');
    productSchemaExecute($db, "CREATE TABLE {$archiveImage} (`piwigo_image_id` MEDIUMINT UNSIGNED NOT NULL,`archive_date` DATE NULL,`date_precision` VARCHAR(16) NOT NULL DEFAULT 'UNKNOWN',`event_label` VARCHAR(190) NULL,PRIMARY KEY (`piwigo_image_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    productSchemaExecute($db, "INSERT INTO {$archiveImage} (`piwigo_image_id`,`archive_date`,`date_precision`,`event_label`) VALUES (900001,NULL,'UNKNOWN',NULL),(900002,'2012-06-01','MONTH','毕业活动')");

    $repository = new \ClassIdentity\Repository($db, $basePrefix);
    $canonical = new \ClassIdentity\CanonicalPhotoService($repository);
    $canonicalId = '10000000-0000-4000-8000-000000000001';
    $aliasId = '10000000-0000-4000-8000-000000000002';

    // Exercise the exact connection-scoped serialization primitive used by
    // BulkArchiveService. A second real MariaDB connection must fail closed
    // while the first owns the same per-photo lock, then acquire it after the
    // owner releases it. This protects the MyISAM association saga rather than
    // merely asserting that GET_LOCK appears in source text.
    $lockPeer = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($lockPeer->connect_errno !== 0 || !$lockPeer->set_charset('utf8mb4')) {
        productSchemaFail('photo_product_lock_peer_database_unavailable');
    }
    $bulkOwner = new \ClassIdentity\BulkArchiveService($repository);
    $bulkPeer = new \ClassIdentity\BulkArchiveService(new \ClassIdentity\Repository($lockPeer, $basePrefix));
    $acquireLocks = new ReflectionMethod(\ClassIdentity\BulkArchiveService::class, 'acquirePhotoLocks');
    $releaseLocks = new ReflectionMethod(\ClassIdentity\BulkArchiveService::class, 'releasePhotoLocks');
    $ownerLocks = $acquireLocks->invoke($bulkOwner, [$canonicalId], 0);
    if (!is_array($ownerLocks) || count($ownerLocks) !== 1) {
        productSchemaFail('photo_product_bulk_owner_lock_missing');
    }
    $peerDenied = false;
    try {
        $acquireLocks->invoke($bulkPeer, [$canonicalId], 0);
    } catch (Throwable $error) {
        $peerDenied = $error->getMessage() === 'class_archive_bulk_photo_lock_unavailable';
    } finally {
        if ($releaseLocks->invoke($bulkOwner, $ownerLocks) !== true) {
            productSchemaFail('photo_product_bulk_owner_lock_release_failed');
        }
    }
    if (!$peerDenied) {
        productSchemaFail('photo_product_concurrent_bulk_lock_not_denied');
    }
    ++$assertions;
    $peerLocks = $acquireLocks->invoke($bulkPeer, [$canonicalId], 0);
    if (!is_array($peerLocks) || count($peerLocks) !== 1 || $releaseLocks->invoke($bulkPeer, $peerLocks) !== true) {
        productSchemaFail('photo_product_bulk_lock_not_reacquirable');
    }
    ++$assertions;

    $canonicalCandidate = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $canonicalId,
        'HERITAGE',
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        null,
        null,
        ['班级档案'],
        'canonical archive',
        900001,
        'UNKNOWN',
        'UNKNOWN',
        null,
        [900001],
    );
    $aliasCandidate = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $aliasId,
        'HERITAGE',
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        '别名保留标题',
        '2012-06-01',
        ['毕业活动'],
        '别名语义检索',
        900002,
        'MONTH',
        'ARCHIVE_CONFIRMED',
        '毕业活动',
        [900002],
    );
    $gateway = new \ClassIdentity\Gateway\GatewayService(
        new ProductCanonicalIdentityAdapter(),
        new ProductCanonicalPiwigoAdapter([$canonicalCandidate, $aliasCandidate]),
        new ProductCanonicalImmichAdapter([$aliasId, $canonicalId]),
        new \ClassIdentity\Gateway\GatewayPolicy(),
        null,
        null,
        null,
        $canonical,
    );
    $photos = $gateway->photos();
    if (($photos['total'] ?? null) !== 1 || ($photos['items'][0]['id'] ?? null) !== $canonicalId) {
        productSchemaFail('photo_product_canonical_photo_count_projection_failed');
    }
    ++$assertions;
    $projected = $photos['items'][0];
    $albums = $projected['albums'] ?? [];
    sort($albums, SORT_STRING);
    if (($projected['title'] ?? null) !== '别名保留标题'
        || ($projected['taken_at'] ?? null) !== '2012-06-01'
        || ($projected['event_label'] ?? null) !== '毕业活动'
        || $albums !== ['毕业活动', '班级档案']
    ) {
        productSchemaFail('photo_product_canonical_archive_projection_failed');
    }
    ++$assertions;
    $conflictingCanonicalCandidate = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $canonicalId, 'HERITAGE', \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE, '冲突目标', '2011-06-01', [], '', 900001,
        'MONTH', 'ARCHIVE_CONFIRMED', '另一场活动', [],
    );
    $conflictingGateway = new \ClassIdentity\Gateway\GatewayService(
        new ProductCanonicalIdentityAdapter(),
        new ProductCanonicalPiwigoAdapter([$conflictingCanonicalCandidate, $aliasCandidate]),
        new ProductCanonicalImmichAdapter([]),
        new \ClassIdentity\Gateway\GatewayPolicy(),
        null, null, null, $canonical,
    );
    try {
        $conflictingGateway->photos();
        productSchemaFail('photo_product_legacy_archive_conflict_not_fail_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_gateway_canonical_archive_metadata_conflict') {
            throw $error;
        }
    }
    ++$assertions;
    $timeline = $gateway->timeline();
    if (($timeline['total'] ?? null) !== 1 || ($timeline['groups'][0]['total'] ?? null) !== 1) {
        productSchemaFail('photo_product_canonical_timeline_count_failed');
    }
    ++$assertions;
    $search = $gateway->search('别名语义检索');
    if (($search['total'] ?? null) !== 1 || ($search['items'][0]['id'] ?? null) !== $canonicalId) {
        productSchemaFail('photo_product_canonical_search_projection_failed');
    }
    ++$assertions;
    $smart = $gateway->smartSearch('synthetic');
    if (($smart['total'] ?? null) !== 1 || ($smart['items'][0]['id'] ?? null) !== $canonicalId) {
        productSchemaFail('photo_product_canonical_smart_search_dedup_failed');
    }
    ++$assertions;
    if ($gateway->mediaCandidate($aliasId) !== null
        || $gateway->mediaCandidate($canonicalId)?->piwigoImageIdForDelivery() !== 900001
    ) {
        productSchemaFail('photo_product_alias_media_not_denied');
    }
    ++$assertions;
    if (count($canonical->provenanceSummary($canonicalId)) !== 3
        || count($canonical->provenanceSummary($aliasId)) !== 3
    ) {
        productSchemaFail('photo_product_canonical_provenance_projection_failed');
    }
    ++$assertions;

    $metadataCompatibility = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'assertArchiveMetadataCompatible');
    $metadataCompatibility->setAccessible(true);
    $metadataCompatibility->invoke($canonical, 900001, 900002);
    ++$assertions;
    foreach ([
        ["UPDATE {$archiveImage} SET `archive_date`='2011-06-01',`date_precision`='MONTH',`event_label`=NULL WHERE `piwigo_image_id`=900001", 'archive_date'],
        ["UPDATE {$archiveImage} SET `archive_date`='2012-06-01',`date_precision`='YEAR',`event_label`=NULL WHERE `piwigo_image_id`=900001", 'date_precision'],
        ["UPDATE {$archiveImage} SET `archive_date`='2012-06-01',`date_precision`='MONTH',`event_label`='另一场活动' WHERE `piwigo_image_id`=900001", 'event_label'],
    ] as [$sql, $field]) {
        productSchemaExecute($db, $sql);
        try {
            $metadataCompatibility->invoke($canonical, 900001, 900002);
            productSchemaFail('photo_product_archive_conflict_not_rejected_' . $field);
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'class_archive_photo_duplicate_archive_metadata_conflict') {
                throw $error;
            }
        }
        ++$assertions;
    }
    productSchemaExecute($db, "UPDATE {$archiveImage} SET `archive_date`=NULL,`date_precision`='UNKNOWN',`event_label`=NULL WHERE `piwigo_image_id`=900001");

    $lockNameMethod = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'consolidationLockName');
    $lockNameMethod->setAccessible(true);
    $acquireLock = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'acquireConsolidationLock');
    $acquireLock->setAccessible(true);
    $releaseLock = new ReflectionMethod(\ClassIdentity\CanonicalPhotoService::class, 'releaseConsolidationLock');
    $releaseLock->setAccessible(true);
    $lockName = (string) $lockNameMethod->invoke($canonical);
    $lockDb = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($lockDb->connect_errno !== 0 || !$lockDb->set_charset('utf8mb4')) {
        productSchemaFail('photo_product_lock_test_database_unavailable');
    }
    $secondCanonical = new \ClassIdentity\CanonicalPhotoService(new \ClassIdentity\Repository($lockDb, $basePrefix));
    $acquireLock->invoke($canonical, $lockName);
    try {
        try {
            $acquireLock->invoke($secondCanonical, $lockName);
            productSchemaFail('photo_product_consolidation_lock_not_exclusive');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'class_archive_photo_consolidation_lock_unavailable') {
                throw $error;
            }
        }
        ++$assertions;
    } finally {
        $releaseLock->invoke($canonical, $lockName);
    }
    $acquireLock->invoke($secondCanonical, $lockName);
    $releaseLock->invoke($secondCanonical, $lockName);
    $lockDb->close();
    ++$assertions;

    $people = $gateway->people();
    if (($people['total'] ?? null) !== 1
        || ($people['items'][0]['photo_count'] ?? null) !== 1
        || ($people['items'][0]['cover_photo_id'] ?? null) !== $canonicalId
    ) {
        productSchemaFail('photo_product_canonical_people_projection_failed');
    }
    ++$assertions;
    $person = $gateway->person('20000000-0000-4000-8000-000000000009');
    if (($person['photo_count'] ?? null) !== 1
        || count($person['items'] ?? []) !== 1
        || ($person['items'][0]['id'] ?? null) !== $canonicalId
    ) {
        productSchemaFail('photo_product_canonical_person_detail_failed');
    }
    ++$assertions;
    $unknownCanonical = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $canonicalId, 'HERITAGE', \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE, null, null, [], '', 900001,
        'UNKNOWN', 'UNKNOWN', null, [],
    );
    $unknownAlias = new \ClassIdentity\Gateway\GatewayPhotoCandidate(
        $aliasId, 'HERITAGE', \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE,
        \ClassIdentity\ClassArchivePhoto::STATE_ACTIVE, null, null, [], '', 900002,
        'UNKNOWN', 'UNKNOWN', null, [],
    );
    $memoryGateway = new \ClassIdentity\Gateway\GatewayService(
        new ProductCanonicalIdentityAdapter(),
        new ProductCanonicalPiwigoAdapter([$unknownCanonical, $unknownAlias]),
        new ProductCanonicalImmichAdapter([$aliasId, $canonicalId]),
        new \ClassIdentity\Gateway\GatewayPolicy(),
        null, null, null, $canonical,
    );
    $memories = $memoryGateway->memories();
    if (($memories['total'] ?? null) !== 1
        || ($memories['items'][0]['photo_count'] ?? null) !== 1
        || ($memories['items'][0]['cover_photo_id'] ?? null) !== $canonicalId
    ) {
        productSchemaFail('photo_product_canonical_memory_projection_failed');
    }
    ++$assertions;
    $physicalCount = $db->query("SELECT COUNT(*) FROM {$photo}");
    $physicalRows = $physicalCount instanceof mysqli_result ? (int) ($physicalCount->fetch_row()[0] ?? 0) : 0;
    if ($physicalCount instanceof mysqli_result) {
        $physicalCount->free();
    }
    if ($physicalRows !== 2) {
        productSchemaFail('photo_product_logical_consolidation_deleted_physical_row');
    }
    ++$assertions;

    fwrite(STDOUT, 'CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=' . ($derive ? 'DERIVED' : 'PASS') . ' assertions=' . $assertions
        . ' album=' . ($derivedDigests['album'] ?? '')
        . ' photo_source=' . ($derivedDigests['photo_source'] ?? '') . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    if ($lockPeer instanceof mysqli) {
        $lockPeer->close();
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (PHOTO_PRODUCT_SCHEMA_SUFFIXES as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . productSchemaIdentifier($tablePrefix . $suffix));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($tablePrefix) . '%';
    $remaining = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $remainingCount = $remaining instanceof mysqli_result ? (int) ($remaining->fetch_row()[0] ?? -1) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'CLASS_ARCHIVE_PHOTO_PRODUCT_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $remainingCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
