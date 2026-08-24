<?php

declare(strict_types=1);

/**
 * Deterministic computational baseline for the Gateway projection layer.
 *
 * Evidence: CONTRACT_TESTED only. It purposefully does not claim a Piwigo
 * HTTP, MediaGuard byte-delivery, browser rendering or Immich ML result. A
 * future runtime scale fixture must use distinct physical synthetic originals
 * because the production adapter correctly rejects two Piwigo rows sharing a
 * single source path.
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
use ClassIdentity\Gateway\GatewayPrincipal;
use ClassIdentity\Gateway\GatewayService;
use ClassIdentity\Gateway\IdentityAdapter;
use ClassIdentity\Gateway\ImmichAdapter;
use ClassIdentity\Gateway\PiwigoAdapter;

const PERFORMANCE_CURSOR_ROOT_SECRET = 'synthetic-performance-cursor-root-v1-7d743fa0f92575ce';

final class PerformanceIdentityAdapter implements IdentityAdapter
{
    public function __construct(private readonly GatewayPrincipal $principal)
    {
    }

    public function currentPrincipal(): ?GatewayPrincipal
    {
        return $this->principal;
    }
}

final class PerformancePiwigoAdapter implements PiwigoAdapter
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

final class PerformanceImmichAdapter implements ImmichAdapter
{
    /** @param array<string,list<string>> $people @param list<string> $smart */
    public function __construct(private readonly array $people, private readonly array $smart)
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
        foreach ($this->people as $personId => $ids) {
            $memberIds = array_values(array_filter($ids, static fn (string $id): bool => isset($allowed[$id])));
            if ($memberIds !== []) {
                $result[] = new GatewayPersonCandidate($personId, null, $memberIds);
            }
        }
        return $result;
    }

    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        unset($visibleClassPhotoIds);
        return [];
    }

    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        unset($query);
        $allowed = array_fill_keys($visibleClassPhotoIds, true);
        return array_values(array_filter($this->smart, static fn (string $id): bool => isset($allowed[$id])));
    }
}

/** Deterministically derive a valid v4 opaque UUID without a database row. */
function performanceUuid(string $namespace, int $index): string
{
    $bytes = substr(hash('sha256', "ClassArchive/{$namespace}/{$index}", true), 0, 16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

/** @return array{p50_ms:float,p95_ms:float,max_ms:float,samples:int} */
function measure(callable $operation, int $expectedTotal): array
{
    $samples = [];
    // One warm-up pass avoids treating PHP's initial class-table work as user
    // request latency. Each measured pass constructs a fresh public result.
    $warm = $operation();
    if (!is_array($warm) || (int) ($warm['total'] ?? -1) !== $expectedTotal) {
        throw new RuntimeException('gateway_performance_warmup_result_invalid');
    }
    for ($run = 0; $run < 7; ++$run) {
        $start = hrtime(true);
        $result = $operation();
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        if (!is_array($result) || (int) ($result['total'] ?? -1) !== $expectedTotal) {
            throw new RuntimeException('gateway_performance_result_invalid');
        }
        $samples[] = $elapsed;
    }
    sort($samples, SORT_NUMERIC);
    $p95 = $samples[(int) ceil((count($samples) * 0.95)) - 1];
    return [
        'p50_ms' => round($samples[(int) floor(count($samples) / 2)], 3),
        'p95_ms' => round($p95, 3),
        'max_ms' => round(max($samples), 3),
        'samples' => count($samples),
    ];
}

/** @return array{pages:int,loaded:int,max_response_bytes:int,limit_bytes:int,cursor_tamper_denied:bool,presentation_epoch_forge_denied:bool} */
function measureTimelinePages(GatewayService $gateway, int $expectedTotal): array
{
    $cursor = null;
    $seen = [];
    $pages = 0;
    $maximumBytes = 0;
    do {
        $page = $gateway->timeline($cursor, 240);
        $encoded = json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $bytes = strlen($encoded);
        if ($bytes >= 1024 * 1024) {
            throw new RuntimeException('gateway_timeline_page_exceeded_bff_bound');
        }
        $maximumBytes = max($maximumBytes, $bytes);
        $count = 0;
        foreach (($page['groups'] ?? []) as $group) {
            if (!is_array($group) || !is_array($group['items'] ?? null)
                || (int) ($group['count'] ?? -1) !== count($group['items'])) {
                throw new RuntimeException('gateway_timeline_page_group_invalid');
            }
            foreach ($group['items'] as $photo) {
                $id = is_array($photo) ? ($photo['id'] ?? null) : null;
                if (!is_string($id) || isset($seen[$id]) || ($photo['era'] ?? null) !== 'HERITAGE') {
                    throw new RuntimeException('gateway_timeline_page_acl_or_identity_invalid');
                }
                $seen[$id] = true;
                ++$count;
            }
        }
        if ($count !== (int) ($page['count'] ?? -1)
            || (int) ($page['total'] ?? -1) !== $expectedTotal
            || (int) ($page['limit'] ?? -1) !== 240) {
            throw new RuntimeException('gateway_timeline_page_envelope_invalid');
        }
        ++$pages;
        $hasMore = ($page['has_more'] ?? null) === true;
        $next = $page['next_cursor'] ?? null;
        if ($hasMore && (!is_string($next) || preg_match('/\A[A-Za-z0-9_-]{48}\z/D', $next) !== 1)) {
            throw new RuntimeException('gateway_timeline_page_cursor_invalid');
        }
        if (!$hasMore && $next !== null) {
            throw new RuntimeException('gateway_timeline_page_terminal_cursor_invalid');
        }
        $cursor = $hasMore ? $next : null;
    } while ($cursor !== null);
    if (count($seen) !== $expectedTotal) {
        throw new RuntimeException('gateway_timeline_page_coverage_invalid');
    }

    $firstPage = $gateway->timeline(null, 240);
    $validCursor = $firstPage['next_cursor'] ?? null;
    if (!is_string($validCursor) || strlen($validCursor) !== 48) {
        throw new RuntimeException('gateway_timeline_valid_cursor_missing');
    }
    $decodedCursor = base64_decode(strtr($validCursor, '-_', '+/'), true);
    if (!is_string($decodedCursor) || strlen($decodedCursor) !== 36) {
        throw new RuntimeException('gateway_timeline_valid_cursor_invalid');
    }
    $originalOffset = unpack('Noffset', substr($decodedCursor, 0, 4));
    $tamperedOffset = max(1, min($expectedTotal - 1, ((int) ($originalOffset['offset'] ?? 0)) + 1));
    $tamperedCursor = rtrim(strtr(base64_encode(pack('N', $tamperedOffset) . substr($decodedCursor, 4)), '+/', '-_'), '=');
    $tamperDenied = false;
    try {
        $gateway->timeline($tamperedCursor, 240);
    } catch (InvalidArgumentException) {
        $tamperDenied = true;
    }
    if (!$tamperDenied) {
        throw new RuntimeException('gateway_timeline_cursor_tamper_not_denied');
    }
    $presentationEpoch = $firstPage['presentation_epoch'] ?? null;
    $snapshot = is_string($presentationEpoch) ? hex2bin($presentationEpoch) : false;
    if (!is_string($snapshot) || strlen($snapshot) !== 32) {
        throw new RuntimeException('gateway_timeline_presentation_epoch_missing');
    }
    // Model a client that knows the public presentation epoch and the cursor
    // format. The old implementation used that public value as its HMAC key;
    // this forged cursor must now fail because only the server owns the
    // domain-separated signing key.
    $forgedOffset = pack('N', $tamperedOffset);
    $forgedMac = hash_hmac('sha256', $forgedOffset . $snapshot, $snapshot, true);
    $forgedCursor = rtrim(strtr(base64_encode($forgedOffset . $forgedMac), '+/', '-_'), '=');
    $epochForgeDenied = false;
    try {
        $gateway->timeline($forgedCursor, 240);
    } catch (InvalidArgumentException) {
        $epochForgeDenied = true;
    }
    if (!$epochForgeDenied) {
        throw new RuntimeException('gateway_timeline_presentation_epoch_forge_not_denied');
    }
    return [
        'pages' => $pages,
        'loaded' => count($seen),
        'max_response_bytes' => $maximumBytes,
        'limit_bytes' => 1024 * 1024,
        'cursor_tamper_denied' => true,
        'presentation_epoch_forge_denied' => true,
    ];
}

/** @return array<string,mixed> */
function benchmark(int $count): array
{
    $candidates = [];
    $people = [];
    $smart = [];
    for ($index = 0; $index < $count; ++$index) {
        $id = performanceUuid('photo', $index);
        $heritage = $index % 2 === 0;
        $precision = match ($index % 7) {
            0 => 'EXACT',
            1 => 'MONTH',
            2 => 'YEAR',
            3 => 'EVENT_ONLY',
            default => 'UNKNOWN',
        };
        $source = match ($precision) {
            'EXACT', 'MONTH', 'YEAR' => 'ARCHIVE_CONFIRMED',
            'EVENT_ONLY' => 'EVENT_INFERENCE',
            default => 'UNKNOWN',
        };
        $takenAt = in_array($precision, ['EXACT', 'MONTH', 'YEAR'], true)
            ? sprintf('20%02d-%02d-%02d', 10 + ($index % 15), 1 + ($index % 12), 1 + ($index % 27))
            : null;
        $event = $precision === 'EVENT_ONLY' ? '合成运动会 ' . ($index % 20) : null;
        $candidates[] = new GatewayPhotoCandidate(
            $id,
            $heritage ? 'HERITAGE' : 'LIVING',
            ClassArchivePhoto::STATE_ACTIVE,
            ClassArchivePhoto::STATE_ACTIVE,
            '合成档案照片 ' . $index,
            $takenAt,
            ['合成相册 ' . ($index % 40)],
            '合成搜索素材 操场 教室 篮球 夜晚 ' . $index,
            0,
            $precision,
            $source,
            $event,
        );
        $personIndex = $index % 200;
        $personId = performanceUuid('person', $personIndex);
        $people[$personId] ??= [];
        $people[$personId][] = $id;
        if ($index % 5 === 0) {
            $smart[] = $id;
        }
    }

    $gateway = new GatewayService(
        new PerformanceIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
        new PerformancePiwigoAdapter($candidates),
        new PerformanceImmichAdapter($people, $smart),
        timelineCursorRootSecret: PERFORMANCE_CURSOR_ROOT_SECRET,
    );
    if ($count === 5_000) {
        $missingSecretGateway = new GatewayService(
            new PerformanceIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
            new PerformancePiwigoAdapter(array_slice($candidates, 0, 2)),
            new PerformanceImmichAdapter([], []),
            timelineCursorRootSecret: '',
        );
        try {
            $missingSecretGateway->timeline();
            throw new RuntimeException('gateway_timeline_cursor_missing_secret_not_denied');
        } catch (RuntimeException $error) {
            if ($error->getMessage() !== 'class_archive_gateway_timeline_cursor_secret_unavailable') {
                throw $error;
            }
        }
    }
    $expectedVisible = intdiv($count + 1, 2);
    $timelinePages = measureTimelinePages($gateway, $expectedVisible);
    $firstCursor = $gateway->timeline(null, 240)['next_cursor'] ?? null;
    $staleCursorDenied = false;
    if (is_string($firstCursor)) {
        $staleGateway = new GatewayService(
            new PerformanceIdentityAdapter(new GatewayPrincipal(Access::ROLE_FAMILY)),
            new PerformancePiwigoAdapter(array_reverse($candidates)),
            new PerformanceImmichAdapter($people, $smart),
            timelineCursorRootSecret: PERFORMANCE_CURSOR_ROOT_SECRET,
        );
        try {
            $staleGateway->timeline($firstCursor, 240);
        } catch (InvalidArgumentException) {
            $staleCursorDenied = true;
        }
    }
    if (!$staleCursorDenied) {
        throw new RuntimeException('gateway_timeline_stale_cursor_not_denied');
    }
    $timelinePages['stale_cursor_denied'] = true;
    $result = [
        'photos' => measure(static fn (): array => $gateway->photos(), $expectedVisible),
        'timeline' => measure(static fn (): array => $gateway->timeline(), $expectedVisible),
        'timeline_pages' => $timelinePages,
        'albums' => measure(static fn (): array => $gateway->albums(), 20),
        'people' => measure(static fn (): array => $gateway->people(), 100),
        'smart_search' => measure(static fn (): array => $gateway->smartSearch('操场'), intdiv($count - 1, 10) + 1),
    ];
    return [
        'assets' => $count,
        'family_visible_assets' => $expectedVisible,
        'measurements' => $result,
        'peak_memory_mib' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    ];
}

try {
    $result = [
        'evidence' => 'CONTRACT_TESTED',
        'five_thousand' => benchmark(5_000),
        'twenty_thousand' => benchmark(20_000),
    ];
    fwrite(STDOUT, 'GATEWAY_PERFORMANCE_CONTRACT=PASS ' . json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'GATEWAY_PERFORMANCE_CONTRACT=FAIL reason=' . $error->getMessage() . "\n");
    exit(1);
}
