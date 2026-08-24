<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;
use ClassIdentity\Repository;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Read-only Piwigo candidate adapter.
 *
 * It uses Piwigo only behind the Class Archive boundary and does not expose
 * Piwigo image/category ids. Every accepted source must already have an ACTIVE
 * checksum mapping; a missing root, path/mapping drift or database error throws
 * so callers fail closed. The hot read path validates the stored reference and
 * leaves physical-file and SHA-256 rechecks to MediaGuard/reconciliation
 * instead of walking the full archive for every thumbnail.
 */
final class PiwigoGatewayAdapter implements PiwigoAdapter
{
    public function __construct(
        private readonly Repository $repository,
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
        return new self($repository, $prefixeTable);
    }

    /** @return list<GatewayPhotoCandidate> */
    public function photoCandidates(): array
    {
        $heritage = $this->rootId('class-archive-heritage');
        $living = $this->rootId('class-archive-living');
        $p = $this->piwigoPrefix;
        $sourceBindings = $this->sourceBindings();
        $rows = $this->repository->fetchAll(
            'SELECT i.`id`, i.`path`, i.`name`, '
            . 'pm.`class_photo_id`, pm.`media_reference` AS `mapping_media_reference`, pm.`state` AS `mapping_state`, '
            . 'ai.`archive_date`, ai.`date_precision`, ai.`date_source`, ai.`event_label`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_heritage`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_living`, '
            . 'GROUP_CONCAT(DISTINCT c.`name` ORDER BY c.`name` SEPARATOR "\\n") AS `album_names` '
            . 'FROM `' . $p . 'images` i '
            . 'JOIN `' . $p . 'image_category` ic ON ic.`image_id` = i.`id` '
            . 'JOIN `' . $p . 'categories` c ON c.`id` = ic.`category_id` '
            . 'LEFT JOIN `' . $this->repository->table('photo') . '` pm ON pm.`piwigo_image_id` = i.`id` '
            . 'LEFT JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id` = i.`id` '
            . 'GROUP BY i.`id`, i.`path`, i.`name`, pm.`class_photo_id`, pm.`media_reference`, pm.`state`, '
            . 'ai.`archive_date`, ai.`date_precision`, ai.`date_source`, ai.`event_label` '
            . 'HAVING `is_heritage` = 1 OR `is_living` = 1 '
            // Piwigo date_available records publication/import time. It is
            // never a capture-date fallback for a historical archive.
            . 'ORDER BY ai.`archive_date` IS NULL ASC, ai.`archive_date` DESC, i.`id` DESC',
            [$heritage, $heritage, $living, $living],
        );
        $categoryIdsByImage = [];
        foreach ($this->repository->fetchAll(
            'SELECT ic.`image_id`,ic.`category_id` FROM `' . $p . 'image_category` ic '
                . 'JOIN `' . $p . 'categories` c ON c.`id`=ic.`category_id` ORDER BY ic.`image_id`,ic.`category_id`',
        ) as $association) {
            $imageId = (int) ($association['image_id'] ?? 0);
            $categoryId = (int) ($association['category_id'] ?? 0);
            if ($imageId <= 0 || $categoryId <= 0) {
                throw new \RuntimeException('class_archive_gateway_album_association_invalid');
            }
            $categoryIdsByImage[$imageId][] = $categoryId;
        }
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
            $imageId = (int) ($row['id'] ?? 0);
            if (!isset($sourceBindings[$path]) || count($sourceBindings[$path]) !== 1 || $sourceBindings[$path][0] !== $imageId) {
                throw new \RuntimeException('class_archive_gateway_source_ambiguous');
            }
            $mappingReference = ClassArchivePhoto::normalizeMediaReference((string) ($row['mapping_media_reference'] ?? ''));
            if (($row['mapping_state'] ?? null) !== ClassArchivePhoto::STATE_ACTIVE || !hash_equals($mappingReference, $path)) {
                throw new \RuntimeException('class_archive_gateway_mapping_not_active');
            }
            $classPhotoId = ClassArchivePhoto::binaryToId((string) ($row['class_photo_id'] ?? ''));
            $albums = is_string($row['album_names'] ?? null) && $row['album_names'] !== ''
                ? explode("\n", (string) $row['album_names'])
                : [];
            $precision = strtoupper((string) ($row['date_precision'] ?? 'UNKNOWN'));
            $source = strtoupper((string) ($row['date_source'] ?? 'UNKNOWN'));
            $archiveDate = $row['archive_date'] ?? null;
            $takenAt = is_string($archiveDate) ? substr($archiveDate, 0, 10) : null;
            if (
                !in_array($source, ['ARCHIVE_CONFIRMED', 'EXIF_TRUSTED'], true)
                || !in_array($precision, ['EXACT', 'DAY', 'MONTH', 'YEAR'], true)
            ) {
                $takenAt = null;
            }
            $result[] = new GatewayPhotoCandidate(
                $classPhotoId,
                $isHeritage ? 'HERITAGE' : 'LIVING',
                ClassArchivePhoto::STATE_ACTIVE,
                ClassArchivePhoto::STATE_ACTIVE,
                self::nullableText($row['name'] ?? null, 190),
                $takenAt,
                $albums,
                (string) (($row['name'] ?? '') . "\n" . ($row['event_label'] ?? '') . "\n" . implode("\n", $albums) . "\n" . ($row['archive_date'] ?? '')),
                $imageId,
                $precision,
                $source,
                self::nullableText($row['event_label'] ?? null, 190),
                array_values(array_unique($categoryIdsByImage[$imageId] ?? [])),
            );
        }

        return $result;
    }

    /** @return array<string,list<int>> */
    private function sourceBindings(): array
    {
        $bindings = [];
        foreach ($this->repository->fetchAll('SELECT `id`,`path` FROM `' . $this->piwigoPrefix . 'images`') as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || !is_string($row['path'] ?? null)) {
                throw new \RuntimeException('class_archive_gateway_source_invalid');
            }
            try {
                $path = ClassArchivePhoto::normalizeMediaReference((string) $row['path']);
            } catch (\Throwable) {
                // Unmanaged Piwigo sources are not candidates. An exact alias
                // of a managed upload/galleries reference necessarily passes
                // this same normalizer and therefore cannot be skipped here.
                continue;
            }
            $bindings[$path][] = $id;
        }
        return $bindings;
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

    private static function nullableText(mixed $value, int $max): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        return $value === '' || strlen($value) > $max || str_contains($value, "\0") ? null : $value;
    }
}
