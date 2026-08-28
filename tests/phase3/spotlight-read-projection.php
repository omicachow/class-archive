<?php

declare(strict_types=1);

function spotlightProjectionFail(string $message): never
{
    throw new RuntimeException($message);
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "SPOTLIGHT_READ_PROJECTION=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtime = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtime) || ($runtime['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "SPOTLIGHT_READ_PROJECTION=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "SPOTLIGHT_READ_PROJECTION=FAIL reason=database_unavailable\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
require $root . '/plugins/ClassIdentity/src/Schema.php';
require $root . '/plugins/ClassIdentity/src/Repository.php';
require $root . '/plugins/ClassIdentity/src/CoreAdapter.php';
require $root . '/plugins/ClassIdentity/src/Access.php';
require $root . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require $root . '/plugins/ClassIdentity/src/DomainSupport.php';
require $root . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require $root . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';
require $root . '/plugins/ClassIdentity/src/Gateway/ReadProjectionStore.php';
require $root . '/tests/support/class-identity-native-projection-fixture.php';

final class SpotlightProjectionIdentity implements \ClassIdentity\Gateway\IdentityAdapter
{
    public function __construct(private readonly string $role) {}

    public function currentPrincipal(): ?\ClassIdentity\Gateway\GatewayPrincipal
    {
        return new \ClassIdentity\Gateway\GatewayPrincipal($this->role);
    }
}

final class SpotlightProjectionPiwigo implements \ClassIdentity\Gateway\PointPiwigoAdapter
{
    public function __construct(private readonly \ClassIdentity\Gateway\ReadProjectionStore $store) {}

    public function photoCandidates(): array { return $this->store->photos(); }

    public function photoCandidate(string $classPhotoId): ?\ClassIdentity\Gateway\GatewayPhotoCandidate
    {
        return $this->store->photo($classPhotoId);
    }
}

final class SpotlightProjectionImmich implements \ClassIdentity\Gateway\ImmichAdapter
{
    public function availability(): string { return 'UNAVAILABLE'; }
    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array { return []; }
    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array { return []; }
    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array { return []; }
}

$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_spot_proj_' . $run . '_';
$schema = new \ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$repository = new \ClassIdentity\Repository($db, $basePrefix);
$store = new \ClassIdentity\Gateway\ReadProjectionStore($repository);
$createdNative = [];
$assertions = 0;
$exit = 0;
$phase = 'setup';
$restartDb = null;

try {
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayReadProjection'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationGatewayAggregateProjection'))->invoke($schema);
    $createdNative = classIdentityCreateNativeProjectionFixture($db, (string) $prefixeTable, $basePrefix);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationNativePiwigoProjectionGuard'))->invoke($schema);
    (new ReflectionMethod(\ClassIdentity\Schema::class, 'migrationDurableNativeSourceEpoch'))->invoke($schema);

    $heritagePhotoId = '10000000-0000-4000-8000-000000000001';
    $livingPhotoId = '10000000-0000-4000-8000-000000000002';
    $spotlightId = '20000000-0000-4000-8000-000000000001';
    $albumId = '30000000-0000-4000-8000-000000000001';
    $photos = [
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($heritagePhotoId, 'HERITAGE', 'ACTIVE', 'ACTIVE', '历史合成照片', null, ['历史相册'], '历史合成照片', 910001, 'UNKNOWN', 'UNKNOWN', null, [201]),
        new \ClassIdentity\Gateway\GatewayPhotoCandidate($livingPhotoId, 'LIVING', 'ACTIVE', 'ACTIVE', '动态合成照片', null, ['动态相册'], '动态合成照片', 910002, 'UNKNOWN', 'UNKNOWN', null, [202]),
    ];
    $photoToken = $store->beginPhotoCatalogBuild();
    $store->rebuildPhotos($photos, false, $photoToken);

    $future = [
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL => [
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => true, 'total' => 1, 'item' => [
                'id' => $spotlightId,
                'albumId' => $albumId,
                'albumName' => '毕业后测试精选',
                'coverPhotoId' => $livingPhotoId,
                'description' => null,
                'publisherLabel' => '班级成员',
                'expiresAt' => '2099-01-01 00:00:00.000000',
            ]],
        ],
        \ClassIdentity\Gateway\ReadProjectionStore::SCOPE_HERITAGE => [
            \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT => ['active' => false, 'total' => 0, 'item' => null],
        ],
    ];
    $publish = static function (
        \ClassIdentity\Gateway\ReadProjectionStore $projectionStore,
        array $payloads,
    ): void {
        $token = $projectionStore->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT]);
        $projectionStore->rebuildAggregates(
            $payloads,
            [\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT],
            $token,
        );
    };
    $phase = 'initial_publish';
    $publish($store, $future);

    // A new store instance models a PHP-FPM restart: the role-specific card is
    // served entirely from MariaDB without a live Spotlight fallback.
    $restartDb = @new mysqli((string) $conf['db_host'], (string) $conf['db_user'], (string) $conf['db_password'], (string) $conf['db_base']);
    if ($restartDb->connect_errno !== 0 || !$restartDb->set_charset('utf8mb4')) {
        spotlightProjectionFail('restart_database_unavailable');
    }
    $restartStore = new \ClassIdentity\Gateway\ReadProjectionStore(new \ClassIdentity\Repository($restartDb, $basePrefix));
    $classmate = new \ClassIdentity\Gateway\GatewayService(
        new SpotlightProjectionIdentity(\ClassIdentity\Access::ROLE_CLASSMATE),
        new SpotlightProjectionPiwigo($restartStore),
        new SpotlightProjectionImmich(),
        readProjection: $restartStore,
    );
    $family = new \ClassIdentity\Gateway\GatewayService(
        new SpotlightProjectionIdentity(\ClassIdentity\Access::ROLE_FAMILY),
        new SpotlightProjectionPiwigo($restartStore),
        new SpotlightProjectionImmich(),
        readProjection: $restartStore,
    );
    $full = $classmate->spotlight();
    $heritage = $family->spotlight();
    if (($full['active'] ?? null) !== true
        || ($full['item']['coverPhotoId'] ?? null) !== $livingPhotoId
        || ($heritage['active'] ?? null) !== false
        || ($heritage['total'] ?? null) !== 0
    ) {
        spotlightProjectionFail('scope_or_restart_contract_invalid');
    }
    $encodedFull = json_encode($full, JSON_THROW_ON_ERROR);
    foreach (['principal', 'account', 'seat', 'owner'] as $forbidden) {
        if (stripos($encodedFull, $forbidden) !== false) {
            spotlightProjectionFail('sensitive_identity_leaked_' . $forbidden);
        }
    }
    $assertions += 5;

    // Cached expiry is a visibility deadline. It may hide a card, but must not
    // perform a source mutation or fall back to live data on a GET.
    $expired = $future;
    $expired[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT]['item']['expiresAt'] = '2000-01-01 00:00:00.000000';
    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT], 'SPOTLIGHT_EXPIRE_TEST', false);
    $phase = 'expired_publish';
    $publish($restartStore, $expired);
    if (($classmate->spotlight()['active'] ?? null) !== false) {
        spotlightProjectionFail('expired_persisted_card_visible');
    }
    $spotlightState = array_values(array_filter(
        $restartStore->status(),
        static fn(array $row): bool => ($row['kind'] ?? null) === \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
    ));
    if (($spotlightState[0]['state'] ?? null) !== 'ACTIVE') {
        spotlightProjectionFail('expiry_read_mutated_projection');
    }
    $assertions += 2;

    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT], 'SPOTLIGHT_RESTORE_TEST', false);
    $phase = 'future_restore_publish';
    $publish($restartStore, $future);
    $staleToken = $restartStore->beginAggregateBuild([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT]);
    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT], 'SPOTLIGHT_CREATE', false);
    $phase = 'old_token_rejection';
    try {
        $restartStore->rebuildAggregates(
            $future,
            [\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT],
            $staleToken,
        );
        spotlightProjectionFail('old_spotlight_token_published');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_publish_race') {
            throw $error;
        }
    }
    try {
        $classmate->spotlight();
        spotlightProjectionFail('stale_spotlight_did_not_fail_closed');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_unavailable') {
            throw $error;
        }
    }
    $phase = 'stale_token_recovery_publish';
    $publish($restartStore, $future);
    if (($classmate->spotlight()['active'] ?? null) !== true) {
        spotlightProjectionFail('fresh_spotlight_rebuild_failed');
    }
    $assertions += 3;

    foreach (['SPOTLIGHT_CANCEL', 'SPOTLIGHT_EXPIRE'] as $reason) {
        $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT], $reason, false);
        $state = array_values(array_filter(
            $restartStore->status(),
            static fn(array $row): bool => ($row['kind'] ?? null) === \ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT,
        ));
        if (($state[0]['state'] ?? null) !== 'STALE' || ($state[0]['reason'] ?? null) !== $reason) {
            spotlightProjectionFail('spotlight_invalidation_reason_invalid');
        }
        $phase = strtolower($reason) . '_recovery_publish';
        $publish($restartStore, $future);
        ++$assertions;
    }

    // PHP date parsing normally normalizes impossible dates. Persisted data is
    // security-sensitive, so malformed deadlines must fail closed instead.
    $invalidDate = $future;
    $invalidDate[\ClassIdentity\Gateway\ReadProjectionStore::SCOPE_FULL][\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT]['item']['expiresAt'] = '2099-02-31 00:00:00.000000';
    $restartStore->invalidate([\ClassIdentity\Gateway\ReadProjectionStore::SPOTLIGHT], 'SPOTLIGHT_INVALID_DATE_TEST', false);
    $phase = 'invalid_date_publish';
    $publish($restartStore, $invalidDate);
    try {
        $classmate->spotlight();
        spotlightProjectionFail('invalid_deadline_accepted');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'class_archive_read_aggregate_payload_invalid') {
            throw $error;
        }
    }
    ++$assertions;

    $spotlightSource = file_get_contents($root . '/plugins/ClassIdentity/src/SpotlightService.php');
    $controllerSource = file_get_contents($root . '/plugins/ClassIdentity/src/Gateway/GatewayHttpController.php');
    $maintenanceSource = file_get_contents($root . '/infra/scripts/run-maintenance.php');
    $albumSource = file_get_contents($root . '/plugins/ClassIdentity/src/AlbumService.php');
    if (!is_string($spotlightSource) || !is_string($controllerSource) || !is_string($maintenanceSource) || !is_string($albumSource)) {
        spotlightProjectionFail('spotlight_sources_unavailable');
    }
    foreach (['SPOTLIGHT_CREATE', 'SPOTLIGHT_CANCEL', 'SPOTLIGHT_EXPIRE'] as $reason) {
        if (!str_contains($spotlightSource, "'{$reason}'")) {
            spotlightProjectionFail('spotlight_source_invalidation_missing_' . strtolower($reason));
        }
    }
$gatewayNamespace = chr(92) . 'ClassIdentity' . chr(92) . 'Gateway' . chr(92);
    if (substr_count($controllerSource, 'rebuildAggregateProjection([ReadProjectionStore::SPOTLIGHT])') < 2
        || !str_contains($maintenanceSource, '$spotlightProjection = ' . $gatewayNamespace . 'ReadProjectionBuilder::rebuild(')
        || !str_contains($maintenanceSource, '[' . $gatewayNamespace . 'ReadProjectionStore::SPOTLIGHT]')
        || substr_count($albumSource, 'ReadProjectionStore::SPOTLIGHT') < 2
    ) {
        spotlightProjectionFail('spotlight_recovery_path_incomplete');
    }
    $assertions += 4;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'SPOTLIGHT_READ_PROJECTION=FAIL phase=' . $phase . ' reason=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    if ($restartDb instanceof mysqli && $restartDb !== $db) {
        $restartDb->close();
    }
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
            fwrite(STDERR, 'SPOTLIGHT_READ_PROJECTION_CLEANUP=FAIL reason=' . $cleanupError->getMessage() . "\n");
            $exit = 1;
        }
    }
    $db->close();
}

if ($exit === 0) {
    fwrite(STDOUT, "SPOTLIGHT_READ_PROJECTION=PASS assertions={$assertions}\n");
}
exit($exit);
