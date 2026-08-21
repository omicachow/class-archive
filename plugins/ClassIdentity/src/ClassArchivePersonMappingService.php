<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Maps an Immich AI cluster to a Class Archive opaque person UUID.
 *
 * The mapping is deliberately one-way for the presentation adapter.  It does
 * not infer a classmate identity, contact Immich, or authorize any media.
 */
final class ClassArchivePersonMappingService
{
    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Return the existing active ClassArchivePerson or create a fresh opaque
     * mapping for a verified internal Immich cluster identifier.
     *
     * @return array{class_person_id:string,display_name:?string,classmate_identity_id:?int,state:string}
     */
    public function ensureImmichCluster(string $immichPersonId): array
    {
        $immichPersonId = ClassArchivePerson::normalizeImmichPersonId($immichPersonId);

        return $this->repository->transaction(function (Repository $repository) use ($immichPersonId): array {
            $row = $repository->fetchOne(
                'SELECT `class_person_id`,`display_name`,`classmate_identity_id`,`state` FROM `'
                . $repository->table('person') . '` WHERE `immich_person_id` = ? FOR UPDATE',
                [$immichPersonId],
            );
            if ($row !== null) {
                $hydrated = self::hydrate($row);
                if ($hydrated['state'] !== ClassArchivePerson::STATE_ACTIVE) {
                    throw new \RuntimeException('class_archive_person_mapping_not_active');
                }
                return $hydrated;
            }

            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $classPersonId = ClassArchivePerson::generateId();
                $binaryId = ClassArchivePerson::idToBinary($classPersonId);
                if ($repository->fetchOne(
                    'SELECT `class_person_id` FROM `' . $repository->table('person') . '` WHERE `class_person_id` = ? LIMIT 1 FOR UPDATE',
                    [$binaryId],
                ) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $repository->table('person') . '` '
                    . '(`class_person_id`,`immich_person_id`,`source_kind`,`state`,`created_at`,`updated_at`) '
                    . 'VALUES (?, ?, \'IMMICH_CLUSTER\', ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))',
                    [$binaryId, $immichPersonId, ClassArchivePerson::STATE_ACTIVE],
                );
                return [
                    'class_person_id' => $classPersonId,
                    'display_name' => null,
                    'classmate_identity_id' => null,
                    'state' => ClassArchivePerson::STATE_ACTIVE,
                ];
            }
            throw new \RuntimeException('class_archive_person_mapping_id_collision');
        });
    }

    /** @return array{class_person_id:string,display_name:?string,classmate_identity_id:?int,state:string}|null */
    public function findByClassPersonId(string $classPersonId): ?array
    {
        $row = $this->repository->fetchOne(
            'SELECT `class_person_id`,`display_name`,`classmate_identity_id`,`state` FROM `'
            . $this->repository->table('person') . '` WHERE `class_person_id` = ? LIMIT 1',
            [ClassArchivePerson::idToBinary($classPersonId)],
        );
        return $row === null ? null : self::hydrate($row);
    }

    /** @param array<string,mixed> $row @return array{class_person_id:string,display_name:?string,classmate_identity_id:?int,state:string} */
    private static function hydrate(array $row): array
    {
        $binaryId = $row['class_person_id'] ?? null;
        $state = $row['state'] ?? null;
        if (!is_string($binaryId) || !is_string($state) || !in_array($state, ClassArchivePerson::states(), true)) {
            throw new \RuntimeException('class_archive_person_mapping_row_invalid');
        }
        $displayName = $row['display_name'] ?? null;
        if ($displayName !== null && (!is_string($displayName) || $displayName === '' || strlen($displayName) > 190 || str_contains($displayName, "\0"))) {
            throw new \RuntimeException('class_archive_person_mapping_row_invalid');
        }
        $identityId = $row['classmate_identity_id'] ?? null;
        if ($identityId !== null && (!is_numeric($identityId) || (int) $identityId <= 0)) {
            throw new \RuntimeException('class_archive_person_mapping_row_invalid');
        }
        return [
            'class_person_id' => ClassArchivePerson::binaryToId($binaryId),
            'display_name' => $displayName,
            'classmate_identity_id' => $identityId === null ? null : (int) $identityId,
            'state' => $state,
        ];
    }
}
