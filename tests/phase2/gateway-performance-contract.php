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
    );
    $expectedVisible = intdiv($count + 1, 2);
    $result = [
        'photos' => measure(static fn (): array => $gateway->photos(), $expectedVisible),
        'timeline' => measure(static fn (): array => $gateway->timeline(), $expectedVisible),
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
