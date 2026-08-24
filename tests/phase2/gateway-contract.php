<?php

declare(strict_types=1);

/**
 * Class Archive Gateway contract and policy gate.
 *
 * Evidence level: CONTRACT_TESTED only. This test deliberately uses adapters
 * with synthetic in-memory candidates: it neither starts Immich nor claims an
 * HTTP/browser E2E result. It proves the API boundary filters candidates
 * before every aggregation and never serializes backend identifiers.
 */

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Repository.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/CoreAdapter.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Access.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/ClassArchivePhoto.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/ClassArchivePerson.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/Contracts.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/GatewayPolicy.php';
require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Gateway/GatewayService.php';

use ClassIdentity\Access;
use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\Gateway\GatewayMemoryCandidate;
use ClassIdentity\Gateway\GatewayPersonCandidate;
use ClassIdentity\Gateway\GatewayPhotoCandidate;
use ClassIdentity\Gateway\GatewayPolicy;
use ClassIdentity\Gateway\GatewayPrincipal;
use ClassIdentity\Gateway\GatewayRouteContract;
use ClassIdentity\Gateway\GatewayService;
use ClassIdentity\Gateway\IdentityAdapter;
use ClassIdentity\Gateway\ImmichAdapter;
use ClassIdentity\Gateway\NullImmichAdapter;
use ClassIdentity\Gateway\PiwigoAdapter;

final class GatewayContractIdentityAdapter implements IdentityAdapter
{
    public function __construct(private readonly ?GatewayPrincipal $principal)
    {
    }

    public function currentPrincipal(): ?GatewayPrincipal
    {
        return $this->principal;
    }
}

final class GatewayContractPiwigoAdapter implements PiwigoAdapter
{
    /** @param list<GatewayPhotoCandidate> $candidates */
    public function __construct(private readonly array $candidates)
    {
    }

    public function photoCandidates(): array
    {
        return $this->candidates;
    }
}

final class GatewayContractBrokenPiwigoAdapter implements PiwigoAdapter
{
    public function photoCandidates(): array
    {
        throw new RuntimeException('synthetic_source_failure');
    }
}

final class GatewayContractImmichAdapter implements ImmichAdapter
{
    /** @param list<GatewayPersonCandidate> $people @param list<GatewayMemoryCandidate> $memories @param list<string> $smartResults */
    public function __construct(private readonly array $people, private readonly array $memories, private readonly array $smartResults)
    {
    }

    public function availability(): string
    {
        return 'AVAILABLE';
    }

    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        $result = [];
        foreach ($this->people as $person) {
            $ids = array_values(array_filter($person->classPhotoIds(), static fn (string $id): bool => isset($allowed[$id])));
            if ($ids !== []) {
                $result[] = new GatewayPersonCandidate($person->id(), $person->label(), $ids);
            }
        }
        return $result;
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        $result = [];
        foreach ($this->memories as $memory) {
            $ids = array_values(array_filter($memory->classPhotoIds(), static fn (string $id): bool => isset($allowed[$id])));
            if ($ids !== []) {
                $result[] = new GatewayMemoryCandidate($memory->label(), $ids);
            }
        }
        return $result;
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($query);
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        return array_values(array_filter($this->smartResults, static fn (string $id): bool => isset($allowed[$id])));
    }
}

/**
 * Deliberately violates the adapter contract so the Gateway test proves it
 * does not trust an enrichment server merely because that server is internal.
 */
final class GatewayContractUnsafeImmichAdapter implements ImmichAdapter
{
    /** @param list<GatewayPersonCandidate> $people @param list<string> $smartResults */
    public function __construct(private readonly array $people, private readonly array $smartResults)
    {
    }

    public function availability(): string
    {
        return 'AVAILABLE';
    }

    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return $this->people;
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($visibleClassPhotoIds, $query);
        return $this->smartResults;
    }
}

$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    if (!$condition) {
        $failures[] = $label;
    }
};
$expects = static function (callable $callback, string $expected, string $label) use (&$assertions, &$failures): void {
    ++$assertions;
    try {
        $callback();
        $failures[] = $label . ':not_thrown';
    } catch (Throwable $error) {
        if ($error->getMessage() !== $expected) {
            $failures[] = $label . ':wrong_error';
        }
    }
};

$heritageId = '10000000-0000-4000-8000-000000000001';
$livingId = '10000000-0000-4000-8000-000000000002';
$pendingId = '10000000-0000-4000-8000-000000000003';
$staleId = '10000000-0000-4000-8000-000000000004';
$personOneId = '20000000-0000-4000-8000-000000000001';
$personTwoId = '20000000-0000-4000-8000-000000000002';
$monthId = '10000000-0000-4000-8000-000000000005';
$yearId = '10000000-0000-4000-8000-000000000006';
$eventId = '10000000-0000-4000-8000-000000000007';
$unknownId = '10000000-0000-4000-8000-000000000008';

$heritage = new GatewayPhotoCandidate(
    $heritageId,
    'HERITAGE',
    ClassArchivePhoto::STATE_ACTIVE,
    ClassArchivePhoto::STATE_ACTIVE,
    '毕业合影',
    '2012-06-01',
    ['毕业前档案', '测试活动'],
    '毕业合影 测试活动 internal-piwigo-image-101 internal-path-upload/secret.jpg',
    0,
    'EXACT',
    'ARCHIVE_CONFIRMED',
);
$living = new GatewayPhotoCandidate(
    $livingId,
    'LIVING',
    ClassArchivePhoto::STATE_ACTIVE,
    ClassArchivePhoto::STATE_ACTIVE,
    '毕业后聚会',
    '2025-01-01',
    ['毕业后动态'],
    '毕业后聚会 internal-immich-asset-aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
    0,
    'MONTH',
    'ARCHIVE_CONFIRMED',
);
$pending = new GatewayPhotoCandidate(
    $pendingId,
    'HERITAGE',
    ClassArchivePhoto::STATE_PENDING,
    ClassArchivePhoto::STATE_PENDING,
    '待审核旧照片',
    null,
    ['投稿审核'],
    'pending private-storage-ref',
);
$stale = new GatewayPhotoCandidate(
    $staleId,
    'HERITAGE',
    ClassArchivePhoto::STATE_ACTIVE,
    ClassArchivePhoto::STATE_STALE,
    '映射异常照片',
    '2011-01-01',
    ['毕业前档案'],
    'stale mapping',
);
$candidates = [$heritage, $living, $pending, $stale];
$piwigo = new GatewayContractPiwigoAdapter($candidates);
$immich = new GatewayContractImmichAdapter(
    [
        new GatewayPersonCandidate($personOneId, '测试人物', [$heritageId, $livingId, $pendingId]),
        new GatewayPersonCandidate($personTwoId, '仅毕业后动态', [$livingId]),
    ],
    [
        new GatewayMemoryCandidate('毕业纪念', [$heritageId, $livingId]),
        new GatewayMemoryCandidate('仅待审核', [$pendingId]),
    ],
    [$heritageId, $livingId, $pendingId],
);
$gatewayFor = static function (string $role) use ($piwigo, $immich): GatewayService {
    return new GatewayService(
        new GatewayContractIdentityAdapter(new GatewayPrincipal($role)),
        $piwigo,
        $immich,
        new GatewayPolicy(),
    );
};

try {
    $binary = ClassArchivePhoto::idToBinary($heritageId);
    $assert(ClassArchivePhoto::binaryToId($binary) === $heritageId, 'canonical_uuid_round_trip');
    $assert(strlen(ClassArchivePhoto::checksumToBinary(str_repeat('a', 64))) === 32, 'checksum_binary_width');
    $expects(static fn () => ClassArchivePhoto::normalizeMediaReference('../upload/private.jpg'), 'class_archive_photo_media_reference_invalid', 'media_reference_traversal_rejected');
    $expects(static fn () => ClassArchivePhoto::normalizeMediaReference('upload/%2e%2e/private.jpg'), 'class_archive_photo_media_reference_invalid', 'media_reference_encoded_rejected');
    $assert(ClassArchivePhoto::normalizeImmichAssetId('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee') === 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', 'future_immich_link_validated');

    $routes = GatewayRouteContract::routes();
    foreach (['/api/photos', '/api/timeline', '/api/albums', '/api/people', '/api/people/{id}', '/api/search', '/api/search/smart', '/api/photos/{id}', '/api/me', '/api/memories'] as $route) {
        $assert(($routes[$route]['method'] ?? null) === 'GET' && ($routes[$route]['evidence'] ?? null) === 'CONTRACT_TESTED', 'route_contract_' . $route);
    }
    $assert(
        ($routes['/api/photos/{id}/media/{thumbnail|xsmall|small|medium|large|preview|original}']['method'] ?? null) === 'GET, HEAD'
        && ($routes['/api/photos/{id}/media/{thumbnail|xsmall|small|medium|large|preview|original}']['evidence'] ?? null) === 'CONTRACT_TESTED',
        'route_contract_canonical_media',
    );
    $assert(GatewayRouteContract::publiclyBound() === true, 'routes_are_explicitly_http_bound');

    $family = $gatewayFor(Access::ROLE_FAMILY);
    $familyPhotos = $family->photos();
    $assert($familyPhotos['total'] === 1 && $familyPhotos['items'][0]['id'] === $heritageId, 'family_heritage_only');
    $assert($family->photo($livingId) === null, 'family_hidden_photo_indistinguishable');
    $assert($family->mediaCandidate($heritageId)?->id() === $heritageId, 'family_canonical_media_candidate_visible');
    $assert($family->mediaCandidate($livingId) === null, 'family_canonical_media_candidate_hidden');
    $familyTimeline = $family->timeline();
    $assert($familyTimeline['total'] === 1 && $familyTimeline['groups'][0]['total'] === 1 && $familyTimeline['groups'][0]['label'] === '2012年06月', 'family_timeline_filtered_before_count');
    $familyAlbums = $family->albums();
    $familyAlbumNames = array_column($familyAlbums['items'], 'name');
    $assert($familyAlbums['total'] === 2 && !in_array('毕业后动态', $familyAlbumNames, true), 'family_album_aggregation_filtered');
    $familySearch = $family->search('毕业后');
    $assert($familySearch['total'] === 0 && $familySearch['items'] === [], 'family_search_filtered_before_count');
    $familyPeople = $family->people();
    $assert($familyPeople['total'] === 1 && $familyPeople['items'][0]['photo_count'] === 1 && $familyPeople['items'][0]['cover_photo_id'] === $heritageId, 'family_people_intersection_count');
    $familyPerson = $family->person($personOneId);
    $assert(is_array($familyPerson) && $familyPerson['photo_count'] === 1 && count($familyPerson['items']) === 1 && $familyPerson['items'][0]['id'] === $heritageId, 'family_person_detail_filtered_before_count');
    $assert($family->person($personTwoId) === null, 'family_person_hidden_indistinguishable');
    $familySmart = $family->smartSearch('操场');
    $assert($familySmart['total'] === 1 && $familySmart['items'][0]['id'] === $heritageId, 'family_smart_search_filtered_before_count');
    $familyMemories = $family->memories();
    $assert($familyMemories['total'] === 1 && $familyMemories['items'][0]['photo_count'] === 1, 'family_memories_intersection_count');
    $familyJson = json_encode([$familyPhotos, $familyTimeline, $familyAlbums, $familySearch, $familyPeople, $familyPerson, $familySmart, $familyMemories], JSON_THROW_ON_ERROR);
    foreach ([$livingId, $pendingId, $staleId, 'internal-piwigo-image-101', 'internal-immich-asset-', 'private-storage-ref', 'media_checksum', 'media_reference', 'piwigo_image_id', 'immich_asset_id'] as $secret) {
        $assert(!str_contains($familyJson, $secret), 'family_no_backend_or_hidden_leak_' . hash('sha256', $secret));
    }

    $monthPhoto = new GatewayPhotoCandidate(
        $monthId,
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        '仅确定月份的旧照片',
        '2011-09-01',
        ['毕业前档案'],
        '月份',
        0,
        'MONTH',
        'ARCHIVE_CONFIRMED',
    );
    $yearPhoto = new GatewayPhotoCandidate(
        $yearId,
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        '仅确定年份的旧照片',
        '2010-01-01',
        ['毕业前档案'],
        '年份',
        0,
        'YEAR',
        'ARCHIVE_CONFIRMED',
    );
    $eventPhoto = new GatewayPhotoCandidate(
        $eventId,
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        '秋季运动会',
        null,
        ['班级活动'],
        '活动',
        0,
        'EVENT_ONLY',
        'EVENT_INFERENCE',
        '秋季运动会',
    );
    $unknownPhoto = new GatewayPhotoCandidate(
        $unknownId,
        'HERITAGE',
        ClassArchivePhoto::STATE_ACTIVE,
        ClassArchivePhoto::STATE_ACTIVE,
        '日期未知的旧照片',
        null,
        ['毕业前档案'],
        '未知',
    );
    $archiveTimelineGateway = new GatewayService(
        new GatewayContractIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
        new GatewayContractPiwigoAdapter([$heritage, $monthPhoto, $yearPhoto, $eventPhoto, $unknownPhoto]),
        $immich,
    );
    $archiveTimeline = $archiveTimelineGateway->timeline();
    $timelineByKey = [];
    foreach ($archiveTimeline['groups'] as $group) {
        $timelineByKey[$group['key']] = $group;
    }
    $assert($archiveTimeline['total'] === 5, 'archive_timeline_total_policy_filtered');
    $assert(($timelineByKey['month:2011-09']['label'] ?? null) === '2011年09月' && ($timelineByKey['month:2011-09']['kind'] ?? null) === 'MONTH', 'archive_timeline_month_precision');
    $assert(($timelineByKey['year:2010']['label'] ?? null) === '2010年' && ($timelineByKey['year:2010']['kind'] ?? null) === 'YEAR', 'archive_timeline_year_precision');
    $eventKey = 'event:' . hash('sha256', '秋季运动会');
    $assert(($timelineByKey[$eventKey]['label'] ?? null) === '秋季运动会' && ($timelineByKey[$eventKey]['kind'] ?? null) === 'EVENT', 'archive_timeline_event_projection');
    $assert(($archiveTimeline['groups'][count($archiveTimeline['groups']) - 1]['key'] ?? null) === 'unknown' && ($archiveTimeline['groups'][count($archiveTimeline['groups']) - 1]['label'] ?? null) === '日期未知', 'archive_timeline_unknown_last');
    $unknownProjection = $unknownPhoto->publicProjection();
    $assert($unknownProjection['taken_at'] === null && $unknownProjection['date_precision'] === 'UNKNOWN', 'archive_timeline_never_falls_back_to_upload_time');

    $unsafeImmich = new GatewayContractUnsafeImmichAdapter(
        [new GatewayPersonCandidate($personOneId, '测试人物', [$heritageId, $livingId])],
        [$heritageId, $livingId],
    );
    $unsafeFamily = new GatewayService(
        new GatewayContractIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
        $piwigo,
        $unsafeImmich,
    );
    $expects(static fn () => $unsafeFamily->people(), 'class_archive_gateway_people_response_invalid', 'people_adapter_hidden_membership_fail_closed');
    $expects(static fn () => $unsafeFamily->smartSearch('操场'), 'class_archive_gateway_smart_search_response_invalid', 'smart_search_adapter_hidden_membership_fail_closed');

    foreach ([Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_ANONYMOUS] as $role) {
        $view = $gatewayFor($role)->photos();
        $ids = array_column($view['items'], 'id');
        $assert($view['total'] === 2 && in_array($heritageId, $ids, true) && in_array($livingId, $ids, true) && !in_array($pendingId, $ids, true), 'full_seat_role_policy_' . $role);
    }
    $admin = $gatewayFor(Access::ROLE_SYSTEM_ADMIN);
    $adminPhotos = $admin->photos();
    $adminIds = array_column($adminPhotos['items'], 'id');
    $assert($adminPhotos['total'] === 3 && in_array($pendingId, $adminIds, true) && !in_array($staleId, $adminIds, true), 'admin_pending_only_mapping_state_fail_closed');

    $nullImmich = new GatewayService(
        new GatewayContractIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
        $piwigo,
        new NullImmichAdapter(),
    );
    $assert($nullImmich->people() === ['available' => false, 'total' => 0, 'items' => []], 'runtime_absent_people_unavailable_not_mocked');
    $nullMemories = $nullImmich->memories();
    $assert($nullMemories['available'] === true && $nullMemories['total'] === 1
        && $nullMemories['items'][0]['cover_photo_id'] === $heritageId,
        'runtime_absent_archive_memories_remain_available');
    $expects(static fn () => $nullImmich->smartSearch('操场'), 'class_archive_gateway_smart_search_unavailable', 'runtime_absent_smart_search_fail_closed');

    $unresolved = new GatewayService(new GatewayContractIdentityAdapter(null), $piwigo, $immich);
    $expects(static fn () => $unresolved->photos(), 'class_archive_gateway_principal_unresolved', 'identity_unknown_fail_closed');
    $broken = new GatewayService(
        new GatewayContractIdentityAdapter(new GatewayPrincipal(Access::ROLE_CLASSMATE)),
        new GatewayContractBrokenPiwigoAdapter(),
        $immich,
    );
    $expects(static fn () => $broken->photos(), 'synthetic_source_failure', 'source_failure_fail_closed');
} catch (Throwable $error) {
    $failures[] = 'unexpected:' . get_class($error) . ':' . $error->getMessage();
}

if ($failures !== []) {
    fwrite(STDERR, 'GATEWAY_CONTRACT=FAIL evidence=CONTRACT_TESTED assertions=' . $assertions . ' failures=' . implode(',', $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, 'GATEWAY_CONTRACT=PASS evidence=CONTRACT_TESTED assertions=' . $assertions . "\n");
