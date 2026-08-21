<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePhotoMappingService;
use ClassIdentity\ClassArchivePerson;
use ClassIdentity\ClassArchivePersonMappingService;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Narrow metadata-only connection to the isolated Immich bridge.
 *
 * The adapter is disabled unless the exact ClassIdentity configuration flag is
 * enabled and a private owner-mode bridge credential is present. It never
 * accepts a caller-supplied URL, it only sends policy-filtered canonical IDs,
 * and it accepts only People/Memory candidate memberships. No path can return
 * an Immich asset URL, original, thumbnail, user, API key or authoritative
 * count to the Class Archive public API.
 */
final class BridgeImmichAdapter implements ImmichAdapter
{
    private const BRIDGE_BASE_URL = 'http://class-archive-immich-gateway:8080/v1';
    private const SECRET_PATH = '_data/.class-archive-immich-bridge.json';
    private const MAX_RESPONSE_BYTES = 1048576;

    private function __construct(
        private readonly ClassArchivePhotoMappingService $mapping,
        private readonly ClassArchivePersonMappingService $personMapping,
        private readonly string $token,
    ) {
    }

    public static function configuredOrNull(): ImmichAdapter
    {
        global $conf;

        $value = is_array($conf ?? null) ? ($conf['class_identity_immich_bridge_enabled'] ?? null) : null;
        if ($value === null || $value === false || $value === 0 || $value === '0' || $value === 'false') {
            return new NullImmichAdapter();
        }
        if (!($value === true || $value === 1 || $value === '1')) {
            throw new \RuntimeException('class_archive_immich_bridge_enablement_invalid');
        }

        return new self(
            ClassArchivePhotoMappingService::fromPiwigo(),
            ClassArchivePersonMappingService::fromPiwigo(),
            self::loadToken(),
        );
    }

    public function availability(): string
    {
        return 'AVAILABLE';
    }

    /** @param list<string> $visibleClassPhotoIds @return list<GatewayPersonCandidate> */
    public function peopleForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $items = $this->requestCandidates('/people', $visibleClassPhotoIds);
        $result = [];
        foreach ($items as $item) {
            $immichPersonId = $item['immich_person_id'] ?? null;
            if (!is_string($immichPersonId)) {
                throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
            }
            $mapped = $this->personMapping->ensureImmichCluster($immichPersonId);
            $result[] = new GatewayPersonCandidate(
                (string) $mapped['class_person_id'],
                $mapped['display_name'] ?? null,
                $item['class_photo_ids'],
            );
        }
        return $result;
    }

    /** @param list<string> $visibleClassPhotoIds @return list<GatewayMemoryCandidate> */
    public function memoriesForVisiblePhotos(array $visibleClassPhotoIds): array
    {
        $items = $this->requestCandidates('/memories', $visibleClassPhotoIds);
        $result = [];
        foreach ($items as $item) {
            $result[] = new GatewayMemoryCandidate($item['label'], $item['class_photo_ids']);
        }
        return $result;
    }

    /** @param list<string> $visibleClassPhotoIds @return list<string> */
    public function smartSearchForVisiblePhotos(array $visibleClassPhotoIds, string $query): array
    {
        $query = trim($query);
        if ($query === '' || strlen($query) > 190 || str_contains($query, "\0")) {
            throw new \InvalidArgumentException('class_archive_immich_bridge_search_invalid');
        }
        $result = [];
        foreach ($this->boundAssetBatches($visibleClassPhotoIds) as $batch) {
            $decoded = $this->post('/search', ['query' => $query, 'assets' => $batch]);
            $ids = $decoded['class_photo_ids'] ?? null;
            if (!is_array($ids) || count($ids) > 500) {
                throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
            }
            $allowed = [];
            foreach ($batch as $asset) {
                $allowed[(string) $asset['class_photo_id']] = true;
            }
            foreach ($ids as $classPhotoId) {
                if (!is_string($classPhotoId) || !isset($allowed[$classPhotoId])) {
                    throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
                }
                ClassArchivePhoto::idToBinary($classPhotoId);
                $result[] = $classPhotoId;
            }
        }
        return $result;
    }

    /**
     * @param list<string> $visibleClassPhotoIds
     * @return list<array{label?:string,immich_person_id?:string,class_photo_ids:list<string>}>
     */
    private function requestCandidates(string $endpoint, array $visibleClassPhotoIds): array
    {
        if (!in_array($endpoint, ['/people', '/memories'], true)) {
            throw new \RuntimeException('class_archive_immich_bridge_endpoint_invalid');
        }
        if ($visibleClassPhotoIds === []) {
            return [];
        }
        $result = [];
        foreach ($this->boundAssetBatches($visibleClassPhotoIds) as $assets) {
            $allowed = [];
            foreach ($assets as $asset) {
                $allowed[(string) $asset['class_photo_id']] = true;
            }
            $decoded = $this->post($endpoint, ['assets' => $assets]);
            $items = $decoded['items'] ?? null;
            if (!is_array($items) || count($items) > 500) {
                throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
                }
                $ids = $item['class_photo_ids'] ?? null;
                if (!is_array($ids)) {
                    throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
                }
                $normalized = $this->normalizeCandidateIds($ids, $allowed);
                if ($endpoint === '/people') {
                    $immichPersonId = $item['immich_person_id'] ?? null;
                    if (!is_string($immichPersonId)) {
                        throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
                    }
                    $result[] = [
                        'immich_person_id' => ClassArchivePerson::normalizeImmichPersonId($immichPersonId),
                        'class_photo_ids' => $normalized,
                    ];
                    continue;
                }
                $label = $item['label'] ?? null;
                if (!is_string($label) || $label === '' || strlen($label) > 190 || str_contains($label, "\0")) {
                    throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
                }
                $result[] = ['label' => $label, 'class_photo_ids' => $normalized];
            }
        }
        if (count($result) > 5000) {
            throw new \RuntimeException('class_archive_immich_bridge_response_too_large');
        }

        return $result;
    }

    /** @param list<string> $visibleClassPhotoIds @return list<list<array{class_photo_id:string,immich_asset_id:string}>> */
    private function boundAssetBatches(array $visibleClassPhotoIds): array
    {
        if (count($visibleClassPhotoIds) > 20000) {
            throw new \InvalidArgumentException('class_archive_immich_bridge_batch_too_large');
        }
        $seen = [];
        $batches = [];
        foreach (array_chunk($visibleClassPhotoIds, 500) as $ids) {
            $bindings = $this->mapping->activeImmichAssetBindings($ids);
            $assets = [];
            foreach ($ids as $classPhotoId) {
                if (!is_string($classPhotoId) || isset($seen[$classPhotoId]) || !isset($bindings[$classPhotoId])) {
                    throw new \RuntimeException('class_archive_immich_bridge_binding_invalid');
                }
                ClassArchivePhoto::idToBinary($classPhotoId);
                $assetId = ClassArchivePhoto::normalizeImmichAssetId($bindings[$classPhotoId]);
                if ($assetId === null) {
                    throw new \RuntimeException('class_archive_immich_bridge_binding_invalid');
                }
                $seen[$classPhotoId] = true;
                $assets[] = ['class_photo_id' => $classPhotoId, 'immich_asset_id' => $assetId];
            }
            if ($assets !== []) {
                $batches[] = $assets;
            }
        }
        return $batches;
    }

    /** @param list<mixed> $ids @param array<string,true> $allowed @return list<string> */
    private function normalizeCandidateIds(array $ids, array $allowed): array
    {
        $seen = [];
        $normalized = [];
        foreach ($ids as $classPhotoId) {
            if (!is_string($classPhotoId) || !isset($allowed[$classPhotoId]) || isset($seen[$classPhotoId])) {
                throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
            }
            ClassArchivePhoto::idToBinary($classPhotoId);
            $seen[$classPhotoId] = true;
            $normalized[] = $classPhotoId;
        }
        if ($normalized === []) {
            throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
        }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function post(string $endpoint, array $payload): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('class_archive_immich_bridge_transport_unavailable');
        }
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $handle = curl_init(self::BRIDGE_BASE_URL . $endpoint);
        if ($handle === false) {
            throw new \RuntimeException('class_archive_immich_bridge_transport_unavailable');
        }
        try {
            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encoded,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->token,
                ],
            ]);
            $body = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_string($body) || strlen($body) > self::MAX_RESPONSE_BYTES || $status !== 200) {
                throw new \RuntimeException('class_archive_immich_bridge_unavailable');
            }
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded)) {
                throw new \RuntimeException('class_archive_immich_bridge_response_invalid');
            }
            return $decoded;
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('class_archive_immich_bridge_unavailable', 0, $error);
        } finally {
            curl_close($handle);
            unset($encoded);
        }
    }

    private static function loadToken(): string
    {
        $path = PHPWG_ROOT_PATH . self::SECRET_PATH;
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (
            !is_array($stat)
            || is_link($path)
            || (($stat['mode'] ?? 0) & 0170000) !== 0100000
            || (($stat['mode'] ?? 0) & 0777) !== 0600
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (function_exists('posix_geteuid') && (int) ($stat['uid'] ?? -1) !== posix_geteuid())
            || (int) ($stat['size'] ?? 0) < 48
            || (int) ($stat['size'] ?? 0) > 512
        ) {
            throw new \RuntimeException('class_archive_immich_bridge_secret_unavailable');
        }
        $raw = file_get_contents($path);
        try {
            $decoded = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable) {
            $decoded = null;
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['version', 'token'] || $decoded['version'] !== 1 || !is_string($decoded['token'])) {
            throw new \RuntimeException('class_archive_immich_bridge_secret_unavailable');
        }
        $token = $decoded['token'];
        if (preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $token) !== 1) {
            throw new \RuntimeException('class_archive_immich_bridge_secret_unavailable');
        }
        return $token;
    }
}
