<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\ClassArchivePhotoMappingService;
use ClassIdentity\Repository;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Read-only Piwigo candidate adapter.
 *
 * It uses Piwigo only behind the Class Archive boundary and does not expose
 * Piwigo image/category ids. Every accepted source is checksum-mapped first;
 * a missing root, bad path, map drift or database error throws so callers can
 * fail closed rather than emit a partially authorized aggregate.
 */
final class PiwigoGatewayAdapter implements PiwigoAdapter
{
    public function __construct(
        private readonly Repository $repository,
        private readonly ClassArchivePhotoMappingService $mapping,
        private readonly string $piwigoPrefix,
    ) {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $piwigoPrefix) !== 1) {
            throw new \InvalidArgumentException('class_archive_gateway_piwigo_prefix_invalid');
        }
    }

    public static function fromPiwigo(): self
    {
        global $prefixeTable;
        if (!is_string($prefixeTable)) {
            throw new \RuntimeException('class_archive_gateway_piwigo_prefix_unavailable');
        }
        $repository = Repository::fromPiwigo();
        return new self($repository, new ClassArchivePhotoMappingService($repository), $prefixeTable);
    }

    /** @return list<GatewayPhotoCandidate> */
    public function photoCandidates(): array
    {
        $heritage = $this->rootId('class-archive-heritage');
        $living = $this->rootId('class-archive-living');
        $p = $this->piwigoPrefix;
        $rows = $this->repository->fetchAll(
            'SELECT i.`id`, i.`path`, i.`name`, i.`date_available`, '
            . 'ai.`archive_date`, ai.`event_label`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_heritage`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_living`, '
            . 'GROUP_CONCAT(DISTINCT c.`name` ORDER BY c.`name` SEPARATOR "\\n") AS `album_names` '
            . 'FROM `' . $p . 'images` i '
            . 'JOIN `' . $p . 'image_category` ic ON ic.`image_id` = i.`id` '
            . 'JOIN `' . $p . 'categories` c ON c.`id` = ic.`category_id` '
            . 'LEFT JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id` = i.`id` '
            . 'GROUP BY i.`id`, i.`path`, i.`name`, i.`date_available`, ai.`archive_date`, ai.`event_label` '
            . 'HAVING `is_heritage` = 1 OR `is_living` = 1 '
            . 'ORDER BY COALESCE(ai.`archive_date`, DATE(i.`date_available`)) DESC, i.`id` DESC',
            [$heritage, $heritage, $living, $living],
        );
        $result = [];
        foreach ($rows as $row) {
            $isHeritage = (int) ($row['is_heritage'] ?? 0) === 1;
            $isLiving = (int) ($row['is_living'] ?? 0) === 1;
            // Cross-era is a MediaGuard DENY condition. It must never turn
            // into a single list/card/count in this API boundary either.
            if ($isHeritage === $isLiving) {
                continue;
            }
            $path = ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
            $this->assertUniqueOriginalBinding((int) ($row['id'] ?? 0), $path);
            $checksum = $this->checksumForPath($path);
            $mapping = $this->mapping->ensurePiwigoMapping((int) ($row['id'] ?? 0), $checksum, $path);
            if (($mapping['state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE) {
                throw new \RuntimeException('class_archive_gateway_mapping_not_active');
            }
            $albums = is_string($row['album_names'] ?? null) && $row['album_names'] !== ''
                ? explode("\n", (string) $row['album_names'])
                : [];
            $takenAt = $row['archive_date'] ?? $row['date_available'] ?? null;
            $takenAt = is_string($takenAt) ? substr($takenAt, 0, 10) : null;
            $result[] = new GatewayPhotoCandidate(
                (string) $mapping['class_photo_id'],
                $isHeritage ? 'HERITAGE' : 'LIVING',
                ClassArchivePhoto::STATE_ACTIVE,
                ClassArchivePhoto::STATE_ACTIVE,
                self::nullableText($row['name'] ?? null, 190),
                $takenAt,
                $albums,
                (string) (($row['name'] ?? '') . "\n" . ($row['event_label'] ?? '')),
            );
        }

        return $result;
    }

    private function rootId(string $permalink): int
    {
        $row = $this->repository->fetchOne(
            'SELECT `id` FROM `' . $this->piwigoPrefix . 'categories` WHERE `permalink` = ? LIMIT 1',
            [$permalink],
        );
        $id = $row === null ? 0 : (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new \RuntimeException('class_archive_gateway_era_root_missing');
        }
        return $id;
    }

    private function checksumForPath(string $relativePath): string
    {
        $path = PHPWG_ROOT_PATH . $relativePath;
        $root = PHPWG_ROOT_PATH . (str_starts_with($relativePath, 'upload/') ? 'upload' : 'galleries');
        $rootReal = realpath($root);
        $fileReal = realpath($path);
        if ($rootReal === false || $fileReal === false || is_link($path) || !is_file($fileReal)) {
            throw new \RuntimeException('class_archive_gateway_media_unavailable');
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $rootReal), '/') . '/';
        $normalizedFile = str_replace('\\', '/', $fileReal);
        if (!str_starts_with($normalizedFile, $rootPrefix)) {
            throw new \RuntimeException('class_archive_gateway_media_outside_root');
        }
        $checksum = hash_file('sha256', $fileReal);
        if (!is_string($checksum)) {
            throw new \RuntimeException('class_archive_gateway_media_checksum_failed');
        }
        ClassArchivePhoto::checksumToBinary($checksum);
        return $checksum;
    }

    /**
     * Match MediaGuard's physical-source ambiguity rule before this adapter
     * creates a canonical mapping or emits any aggregate. Piwigo's images.path
     * is not unique, so two rows can otherwise classify identical bytes under
     * different eras. A Gateway response must fail closed rather than choose
     * the apparently less-restricted row.
     */
    private function assertUniqueOriginalBinding(int $imageId, string $relativePath): void
    {
        if ($imageId <= 0) {
            throw new \RuntimeException('class_archive_gateway_image_id_invalid');
        }
        $rows = $this->repository->fetchAll(
            'SELECT `id` FROM `' . $this->piwigoPrefix . 'images` WHERE `path` = ? OR `path` = ?',
            ['./' . $relativePath, $relativePath],
        );
        if (count($rows) !== 1 || (int) ($rows[0]['id'] ?? 0) !== $imageId) {
            throw new \RuntimeException('class_archive_gateway_source_ambiguous');
        }
    }

    private static function nullableText(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' || strlen($value) > $max || str_contains($value, "\0") ? null : $value;
    }
}
