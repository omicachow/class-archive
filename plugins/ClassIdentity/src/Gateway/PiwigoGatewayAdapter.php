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
final class PiwigoGatewayAdapter implements PointPiwigoAdapter
{
    public function __construct(
        private readonly Repository $repository,
        private readonly string $piwigoPrefix,
        private readonly ?ReadProjectionStore $readProjection = null,
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
        return new self($repository, $prefixeTable, new ReadProjectionStore($repository));
    }

    /** @return list<GatewayPhotoCandidate> */
    public function photoCandidates(): array
    {
        return $this->readProjection === null
            ? $this->sourcePhotoCandidatesForRebuild()
            : $this->readProjection->photos();
    }

    public function photoCandidate(string $classPhotoId): ?GatewayPhotoCandidate
    {
        if ($this->readProjection === null) {
            throw new \RuntimeException('class_archive_read_projection_unavailable');
        }
        return $this->readProjection->photo($classPhotoId);
    }

    /**
     * Live source scan used only by the explicit rebuild command. HTTP reads
     * never call this method and therefore never rebuild on a cache miss.
     *
     * @return list<GatewayPhotoCandidate>
     */
    public function sourcePhotoCandidatesForRebuild(): array
    {
        return $this->sourcePhotoCandidatesFromSource(null);
    }

    /**
     * Point source lookup for an already validated write set. This is the
     * incremental counterpart to the explicit full maintenance rebuild: an
     * archive mutation must not scan every image merely to refresh one or a
     * few durable read rows.
     *
     * @param list<string> $classPhotoIds
     * @return list<GatewayPhotoCandidate>
     */
    public function sourcePhotoCandidatesByIdsForRebuild(array $classPhotoIds): array
    {
        if ($classPhotoIds === [] || count($classPhotoIds) > 500) {
            throw new \InvalidArgumentException('class_archive_gateway_source_photo_ids_invalid');
        }
        $normalized = [];
        foreach ($classPhotoIds as $classPhotoId) {
            if (!is_string($classPhotoId)) {
                throw new \InvalidArgumentException('class_archive_gateway_source_photo_id_invalid');
            }
            $id = strtolower(trim($classPhotoId));
            ClassArchivePhoto::idToBinary($id);
            if (isset($normalized[$id])) {
                throw new \InvalidArgumentException('class_archive_gateway_source_photo_id_duplicate');
            }
            $normalized[$id] = true;
        }
        $result = $this->sourcePhotoCandidatesFromSource(array_keys($normalized));
        if (count($result) !== count($normalized)) {
            throw new \RuntimeException('class_archive_gateway_source_photo_mapping_incomplete');
        }
        $found = [];
        foreach ($result as $candidate) {
            $found[$candidate->id()] = $candidate;
        }
        $ordered = [];
        foreach (array_keys($normalized) as $id) {
            if (!isset($found[$id])) {
                throw new \RuntimeException('class_archive_gateway_source_photo_mapping_incomplete');
            }
            $ordered[] = $found[$id];
        }
        return $ordered;
    }

    /**
     * @param list<string>|null $classPhotoIds
     * @return list<GatewayPhotoCandidate>
     */
    private function sourcePhotoCandidatesFromSource(?array $classPhotoIds): array
    {
        $heritage = $this->rootId('class-archive-heritage');
        $living = $this->rootId('class-archive-living');
        $p = $this->piwigoPrefix;
        $where = '';
        $parameters = [$heritage, $heritage, $living, $living];
        if ($classPhotoIds !== null) {
            $binaryIds = array_map([ClassArchivePhoto::class, 'idToBinary'], $classPhotoIds);
            $where = 'WHERE pm.`class_photo_id` IN (' . implode(',', array_fill(0, count($binaryIds), '?')) . ') ';
            array_push($parameters, ...$binaryIds);
        }
        $rows = $this->repository->fetchAll(
            'SELECT i.`id`, i.`path`, i.`name`, i.`width`, i.`height`, '
            . 'pm.`class_photo_id`, pm.`media_checksum`, pm.`media_reference` AS `mapping_media_reference`, pm.`state` AS `mapping_state`, '
            . 'ai.`archive_date`, ai.`date_precision`, ai.`date_source`, ai.`event_label`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_heritage`, '
            . 'MAX(CASE WHEN (ic.`category_id` = ? OR FIND_IN_SET(?, c.`uppercats`) > 0) THEN 1 ELSE 0 END) AS `is_living`, '
            . 'GROUP_CONCAT(DISTINCT c.`name` ORDER BY c.`name` SEPARATOR "\\n") AS `album_names` '
            . 'FROM `' . $p . 'images` i '
            . 'JOIN `' . $p . 'image_category` ic ON ic.`image_id` = i.`id` '
            . 'JOIN `' . $p . 'categories` c ON c.`id` = ic.`category_id` '
            . 'LEFT JOIN `' . $this->repository->table('photo') . '` pm ON pm.`piwigo_image_id` = i.`id` '
            . 'LEFT JOIN `' . $this->repository->table('archive_image') . '` ai ON ai.`piwigo_image_id` = i.`id` '
            . $where
            . 'GROUP BY i.`id`, i.`path`, i.`name`, i.`width`, i.`height`, pm.`class_photo_id`, pm.`media_checksum`, pm.`media_reference`, pm.`state`, '
            . 'ai.`archive_date`, ai.`date_precision`, ai.`date_source`, ai.`event_label` '
            . 'HAVING `is_heritage` = 1 OR `is_living` = 1 '
            // Piwigo date_available records publication/import time. It is
            // never a capture-date fallback for a historical archive.
            . 'ORDER BY ai.`archive_date` IS NULL ASC, ai.`archive_date` DESC, i.`id` DESC',
            $parameters,
        );
        $imageIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows)));
        $categoryIdsByImage = [];
        $categoryWhere = '';
        $categoryParameters = [];
        if ($classPhotoIds !== null) {
            if ($imageIds === [] || in_array(0, $imageIds, true)) {
                throw new \RuntimeException('class_archive_gateway_album_association_invalid');
            }
            $categoryWhere = ' WHERE ic.`image_id` IN (' . implode(',', array_fill(0, count($imageIds), '?')) . ')';
            $categoryParameters = $imageIds;
        }
        foreach ($this->repository->fetchAll(
            'SELECT ic.`image_id`,ic.`category_id` FROM `' . $p . 'image_category` ic '
                . 'JOIN `' . $p . 'categories` c ON c.`id`=ic.`category_id`'
                . $categoryWhere . ' ORDER BY ic.`image_id`,ic.`category_id`',
            $categoryParameters,
        ) as $association) {
            $imageId = (int) ($association['image_id'] ?? 0);
            $categoryId = (int) ($association['category_id'] ?? 0);
            if ($imageId <= 0 || $categoryId <= 0) {
                throw new \RuntimeException('class_archive_gateway_album_association_invalid');
            }
            $categoryIdsByImage[$imageId][] = $categoryId;
        }
        $paths = [];
        foreach ($rows as $row) {
            $paths[] = ClassArchivePhoto::normalizeMediaReference((string) ($row['path'] ?? ''));
        }
        $sourceBindings = $this->sourceBindings($classPhotoIds === null ? null : array_values(array_unique($paths)));
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
            $mappingChecksum = $row['media_checksum'] ?? null;
            if (!is_string($mappingChecksum) || strlen($mappingChecksum) !== 32) {
                throw new \RuntimeException('class_archive_gateway_mapping_checksum_invalid');
            }
            $mediaRevision = substr(
                hash('sha256', "class-archive-media-revision-v1\0" . $mappingChecksum),
                0,
                32,
            );
            $sourceWidth = (int) ($row['width'] ?? 0);
            $sourceHeight = (int) ($row['height'] ?? 0);
            if ($sourceWidth <= 0 || $sourceWidth > 200000 || $sourceHeight <= 0 || $sourceHeight > 200000) {
                throw new \RuntimeException('class_archive_gateway_media_dimensions_invalid');
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
                $mediaRevision,
                $sourceWidth,
                $sourceHeight,
            );
        }

        return $result;
    }

    /** @param list<string>|null $paths @return array<string,list<int>> */
    private function sourceBindings(?array $paths = null): array
    {
        $bindings = [];
        $sql = 'SELECT `id`,`path` FROM `' . $this->piwigoPrefix . 'images`';
        $parameters = [];
        if ($paths !== null) {
            if ($paths === []) {
                return [];
            }
            $accepted = [];
            foreach ($paths as $path) {
                $accepted[$path] = true;
                $accepted['./' . $path] = true;
            }
            $parameters = array_keys($accepted);
            $sql .= ' WHERE `path` IN (' . implode(',', array_fill(0, count($parameters), '?')) . ')';
        }
        foreach ($this->repository->fetchAll($sql, $parameters) as $row) {
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
