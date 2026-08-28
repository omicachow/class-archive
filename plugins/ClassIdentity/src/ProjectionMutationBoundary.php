<?php

declare(strict_types=1);

namespace ClassIdentity;

use ClassIdentity\Gateway\ReadProjectionStore;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * One fail-closed write boundary shared by web, native Admin, CLI and tests.
 *
 * Services call this with their existing Repository from inside the same
 * InnoDB transaction, before changing projection source state. Rebuilding is
 * deliberately post-commit; a failed rebuild leaves STALE rows and HTTP 503
 * rather than serving an older authorization-sensitive aggregate.
 */
final class ProjectionMutationBoundary
{
    /** @return list<string> */
    public static function allAggregateKinds(): array
    {
        return [
            ReadProjectionStore::TIMELINE,
            ReadProjectionStore::ALBUMS,
            ReadProjectionStore::PEOPLE,
            ReadProjectionStore::MEMORIES,
            ReadProjectionStore::SPOTLIGHT,
        ];
    }

    /** @param list<string> $kinds */
    public static function invalidateAggregates(
        Repository $repository,
        array $kinds,
        string $reason,
    ): void {
        (new ReadProjectionStore($repository))->invalidate($kinds, $reason, false);
    }

    /** @param list<string> $kinds */
    public static function invalidatePhotos(
        Repository $repository,
        array $kinds,
        string $reason,
    ): void {
        if ($kinds === []) {
            // A changed photo can affect at least one count/aggregation. An
            // omitted dependency list would let refreshPhotos() rebind every
            // old aggregate to the new catalog generation, so reject it
            // instead of guessing open.
            throw new \InvalidArgumentException('class_archive_projection_dependencies_missing');
        }
        (new ReadProjectionStore($repository))->invalidate(
            array_values(array_unique(array_merge([ReadProjectionStore::PHOTO_CATALOG], $kinds))),
            $reason,
            false,
        );
    }

    /** @param array<string,mixed> $changes @return list<string> */
    public static function archiveKinds(array $changes): array
    {
        if (array_key_exists('era', $changes)) {
            return self::allAggregateKinds();
        }
        // Piwigo image/category associations are MyISAM and are protected by
        // the native BEFORE triggers. Those triggers deliberately rotate all
        // aggregate epochs because an out-of-band association can change Era
        // visibility as well as album membership. A trusted bulk archive write
        // must therefore recover the same conservative set after its bounded
        // catalog refresh; otherwise refreshPhotos() would try to rebind an
        // aggregate that the native guard has already marked STALE.
        foreach (['add_album_ids', 'remove_album_ids'] as $field) {
            if (array_key_exists($field, $changes) && is_array($changes[$field]) && $changes[$field] !== []) {
                return self::allAggregateKinds();
            }
        }
        $kinds = [];
        foreach (['archive_date', 'date_precision', 'date_confidence', 'date_source', 'event_label', 'official'] as $field) {
            if (array_key_exists($field, $changes)) {
                $kinds[ReadProjectionStore::TIMELINE] = true;
                $kinds[ReadProjectionStore::MEMORIES] = true;
            }
        }
        return array_keys($kinds);
    }
}
