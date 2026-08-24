<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Reversible ClassArchivePerson presentation curation; Immich is untouched. */
final class PersonCurationService
{
    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return array<string,mixed> */
    public function createManualPerson(
        int $adminUserId,
        string $displayName,
        ?int $classmateIdentityId,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $displayName = DomainSupport::boundedText($displayName, 190, true) ?? '';
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->validateIdentityLink($classmateIdentityId);

        return $this->repository->transaction(function (Repository $repository) use ($admin, $displayName, $classmateIdentityId, $reason): array {
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $id = DomainSupport::generateId();
                $binary = DomainSupport::idToBinary($id);
                if ($repository->fetchOne(
                    'SELECT `class_person_id` FROM `' . $repository->table('person') . '` WHERE `class_person_id` = ? FOR UPDATE',
                    [$binary],
                ) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . $repository->table('person') . '` '
                    . '(`class_person_id`,`display_name`,`classmate_identity_id`,`source_kind`,`visibility`,`state`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, 'MANUAL', 'VISIBLE', 'ACTIVE', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$binary, $displayName, $classmateIdentityId],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PERSON_CREATE_MANUAL',
                    'target_type' => 'PERSON',
                    'target_id' => $id,
                    'new_value' => [
                        'class_person_id' => $id,
                        'display_name' => $displayName,
                        'classmate_identity_id' => $classmateIdentityId,
                        'source_kind' => 'MANUAL',
                        'visibility' => 'VISIBLE',
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return $this->hydratePerson([
                    'class_person_id' => $binary,
                    'immich_person_id' => null,
                    'display_name' => $displayName,
                    'classmate_identity_id' => $classmateIdentityId,
                    'manual_cover_class_photo_id' => null,
                    'source_kind' => 'MANUAL',
                    'visibility' => 'VISIBLE',
                    'state' => 'ACTIVE',
                    'lock_version' => 0,
                ], true);
            }
            throw new \RuntimeException('class_archive_person_id_collision');
        });
    }

    /** @return array<string,mixed> */
    public function updatePerson(
        int $adminUserId,
        string $classPersonId,
        ?string $displayName,
        ?int $classmateIdentityId,
        string $visibility,
        ?string $coverClassPhotoId,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $displayName = DomainSupport::boundedText($displayName, 190);
        $visibility = strtoupper(trim($visibility));
        if (!in_array($visibility, ['VISIBLE', 'HIDDEN'], true)) {
            throw new \InvalidArgumentException('class_archive_person_visibility_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->validateIdentityLink($classmateIdentityId);
        $coverBinary = null;
        if ($coverClassPhotoId !== null) {
            DomainSupport::requireActivePhoto($this->repository, $coverClassPhotoId);
            $coverBinary = DomainSupport::idToBinary($coverClassPhotoId);
        }

        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $classPersonId, $displayName, $classmateIdentityId, $visibility,
            $coverClassPhotoId, $coverBinary, $reason,
        ): array {
            $binary = DomainSupport::idToBinary($classPersonId);
            $before = $repository->fetchOne(
                'SELECT * FROM `' . $repository->table('person') . '` WHERE `class_person_id` = ? FOR UPDATE',
                [$binary],
            );
            if ($before === null || ($before['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_person_not_active');
            }
            $changed = $repository->execute(
                'UPDATE `' . $repository->table('person') . '` SET `display_name` = ?, `classmate_identity_id` = ?, '
                . '`manual_cover_class_photo_id` = ?, `visibility` = ?, `lock_version` = `lock_version` + 1, '
                . '`updated_at` = UTC_TIMESTAMP(6) WHERE `class_person_id` = ? AND `state` = \'ACTIVE\'',
                [$displayName, $classmateIdentityId, $coverBinary, $visibility, $binary],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_person_update_race');
            }
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PERSON_UPDATE',
                'target_type' => 'PERSON',
                'target_id' => $classPersonId,
                'old_value' => [
                    'display_name' => $before['display_name'] ?? null,
                    'classmate_identity_id' => isset($before['classmate_identity_id']) ? (int) $before['classmate_identity_id'] : null,
                    'cover_class_photo_id' => isset($before['manual_cover_class_photo_id'])
                        ? DomainSupport::binaryToId((string) $before['manual_cover_class_photo_id']) : null,
                    'visibility' => (string) ($before['visibility'] ?? 'VISIBLE'),
                ],
                'new_value' => [
                    'display_name' => $displayName,
                    'classmate_identity_id' => $classmateIdentityId,
                    'cover_class_photo_id' => $coverClassPhotoId,
                    'visibility' => $visibility,
                ],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            $after = $before;
            $after['display_name'] = $displayName;
            $after['classmate_identity_id'] = $classmateIdentityId;
            $after['manual_cover_class_photo_id'] = $coverBinary;
            $after['visibility'] = $visibility;
            $after['lock_version'] = (int) ($before['lock_version'] ?? 0) + 1;
            return $this->hydratePerson($after, true);
        });
    }

    public function merge(int $adminUserId, string $sourceClassPersonId, string $targetClassPersonId, string $reason): string
    {
        return $this->mergeMany($adminUserId, [$sourceClassPersonId], $targetClassPersonId, null, $reason)[0];
    }

    /** @param list<string> $sourceClassPersonIds @return list<string> */
    public function mergeMany(
        int $adminUserId,
        array $sourceClassPersonIds,
        string $targetClassPersonId,
        ?string $targetCoverClassPhotoId,
        string $reason,
    ): array {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        DomainSupport::idToBinary($targetClassPersonId);
        $sources = [];
        foreach ($sourceClassPersonIds as $source) {
            if (!is_string($source)) {
                throw new \InvalidArgumentException('class_archive_person_merge_source_invalid');
            }
            DomainSupport::idToBinary($source);
            $source = strtolower($source);
            if (hash_equals($source, strtolower($targetClassPersonId))) {
                throw new \InvalidArgumentException('class_archive_person_merge_same');
            }
            $sources[$source] = true;
        }
        if ($sources === [] || count($sources) > 100) {
            throw new \InvalidArgumentException('class_archive_person_merge_source_count_invalid');
        }
        $targetCoverBinary = null;
        if ($targetCoverClassPhotoId !== null) {
            $targetCoverBinary = DomainSupport::idToBinary($targetCoverClassPhotoId);
        }
        $sourceIds = array_keys($sources);
        sort($sourceIds, SORT_STRING);
        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $sourceIds, $targetClassPersonId, $targetCoverClassPhotoId, $targetCoverBinary, $reason,
        ): array {
            $allIds = array_values(array_unique(array_merge($sourceIds, [strtolower($targetClassPersonId)])));
            sort($allIds, SORT_STRING);
            $binaryIds = array_map([DomainSupport::class, 'idToBinary'], $allIds);
            $locked = $repository->fetchAll(
                'SELECT `class_person_id`,`state`,`manual_cover_class_photo_id` FROM `' . $repository->table('person') . '` WHERE `class_person_id` IN ('
                    . implode(',', array_fill(0, count($binaryIds), '?')) . ') FOR UPDATE',
                $binaryIds,
            );
            if (count($locked) !== count($binaryIds)) {
                throw new \RuntimeException('class_archive_person_not_active');
            }
            foreach ($locked as $row) {
                if (($row['state'] ?? null) !== 'ACTIVE') {
                    throw new \RuntimeException('class_archive_person_not_active');
                }
            }
            $target = DomainSupport::idToBinary($targetClassPersonId);
            $resolvedTarget = $this->resolveBinaryProjectionPerson($target, true);
            $resolvedTargetId = DomainSupport::binaryToId($resolvedTarget);
            // The resolved target can itself be the destination of an older
            // projection merge and therefore need not have been present in
            // the caller-supplied lock set. Lock its presentation row before
            // any merge or cover mutation.
            $resolvedTargetRow = $this->requireActivePerson($resolvedTarget, true);
            if ($targetCoverClassPhotoId !== null) {
                // Cover validity is checked under the same transaction as the
                // merge so a concurrent archive-state change cannot leave a
                // newly selected inactive cover behind.
                DomainSupport::requireActivePhoto($repository, $targetCoverClassPhotoId, true);
            }
            $audit = new Audit($repository);
            $mergeIds = [];
            foreach ($sourceIds as $sourceId) {
                $source = DomainSupport::idToBinary($sourceId);
                if (hash_equals($resolvedTarget, $source)) {
                    throw new \RuntimeException('class_archive_person_merge_cycle');
                }
                if ($repository->fetchOne(
                    'SELECT `merge_id` FROM `' . DomainSupport::table($repository, 'person_merge') . '` '
                    . "WHERE `source_class_person_id` = ? AND `state` = 'ACTIVE' FOR UPDATE",
                    [$source],
                ) !== null) {
                    throw new \RuntimeException('class_archive_person_already_merged');
                }
                $mergeId = DomainSupport::generateId();
                $repository->execute(
                    'INSERT INTO `' . DomainSupport::table($repository, 'person_merge') . '` '
                    . '(`merge_id`,`source_class_person_id`,`target_class_person_id`,`state`,`created_by_principal_id`,`reason`,`created_at`) '
                    . "VALUES (?, ?, ?, 'ACTIVE', ?, ?, UTC_TIMESTAMP(6))",
                    [DomainSupport::idToBinary($mergeId), $source, $resolvedTarget, (int) $admin['principal_id'], $reason],
                );
                $audit->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PERSON_MERGE',
                    'target_type' => 'PERSON_MERGE',
                    'target_id' => $mergeId,
                    'new_value' => [
                        'merge_id' => $mergeId,
                        'from' => $sourceId,
                        'to' => $resolvedTargetId,
                        'state' => 'ACTIVE',
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                $mergeIds[] = $mergeId;
            }
            $currentCoverBinary = is_string($resolvedTargetRow['manual_cover_class_photo_id'] ?? null)
                ? (string) $resolvedTargetRow['manual_cover_class_photo_id']
                : null;
            if ($targetCoverBinary !== null
                && ($currentCoverBinary === null || !hash_equals($currentCoverBinary, $targetCoverBinary))
            ) {
                $oldCover = $currentCoverBinary !== null
                    ? DomainSupport::binaryToId($currentCoverBinary)
                    : null;
                $changed = $repository->execute(
                    'UPDATE `' . $repository->table('person') . '` SET `manual_cover_class_photo_id`=?,`lock_version`=`lock_version`+1,'
                        . '`updated_at`=UTC_TIMESTAMP(6) WHERE `class_person_id`=? AND `state`=\'ACTIVE\'',
                    [$targetCoverBinary, $resolvedTarget],
                );
                if ($changed !== 1) {
                    throw new \RuntimeException('class_archive_person_cover_update_race');
                }
                // A cover chosen alongside a merge is an independent,
                // explicitly audited presentation change. Reverting a merge
                // must not silently mutate that separate operator choice;
                // projection still refuses to emit a cover outside the
                // role-visible photo set and safely chooses a visible fallback.
                $audit->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PERSON_UPDATE',
                    'target_type' => 'PERSON',
                    'target_id' => $resolvedTargetId,
                    'old_value' => ['cover_class_photo_id' => $oldCover],
                    'new_value' => ['cover_class_photo_id' => $targetCoverClassPhotoId],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            }
            return $mergeIds;
        });
    }

    /** @param list<string> $classPersonIds */
    public function setVisibilityBulk(int $adminUserId, array $classPersonIds, string $visibility, string $reason): int
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $visibility = strtoupper(trim($visibility));
        if (!in_array($visibility, ['VISIBLE', 'HIDDEN'], true)) {
            throw new \InvalidArgumentException('class_archive_person_visibility_invalid');
        }
        $ids = [];
        foreach ($classPersonIds as $id) {
            if (!is_string($id)) {
                throw new \InvalidArgumentException('class_archive_person_visibility_id_invalid');
            }
            DomainSupport::idToBinary($id);
            $ids[strtolower($id)] = true;
        }
        if ($ids === [] || count($ids) > 500) {
            throw new \InvalidArgumentException('class_archive_person_visibility_count_invalid');
        }
        ksort($ids, SORT_STRING);
        return $this->repository->transaction(function (Repository $repository) use ($admin, $ids, $visibility, $reason): int {
            $audit = new Audit($repository);
            foreach (array_keys($ids) as $id) {
                $binary = DomainSupport::idToBinary($id);
                $row = $this->requireActivePerson($binary, true);
                $before = (string) ($row['visibility'] ?? 'VISIBLE');
                if ($before !== $visibility) {
                    $repository->execute(
                        'UPDATE `' . $repository->table('person') . '` SET `visibility`=?,`lock_version`=`lock_version`+1,'
                            . '`updated_at`=UTC_TIMESTAMP(6) WHERE `class_person_id`=? AND `state`=\'ACTIVE\'',
                        [$visibility, $binary],
                    );
                }
                $audit->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PERSON_VISIBILITY_UPDATE',
                    'target_type' => 'PERSON',
                    'target_id' => $id,
                    'old_value' => ['visibility' => $before],
                    'new_value' => ['visibility' => $visibility],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            }
            return count($ids);
        });
    }

    public function revertMerge(int $adminUserId, string $mergeId, string $reason): void
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $mergeId, $reason): void {
            $binary = DomainSupport::idToBinary($mergeId);
            $row = $repository->fetchOne(
                'SELECT `source_class_person_id`,`target_class_person_id`,`state` FROM `'
                . DomainSupport::table($repository, 'person_merge') . '` WHERE `merge_id` = ? FOR UPDATE',
                [$binary],
            );
            if ($row === null || ($row['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_person_merge_not_active');
            }
            $sourceId = DomainSupport::binaryToId((string) $row['source_class_person_id']);
            $targetId = DomainSupport::binaryToId((string) $row['target_class_person_id']);
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'person_merge') . '` '
                . "SET `state` = 'REVERTED',`reverted_by_principal_id` = ?,`reverted_at` = UTC_TIMESTAMP(6) WHERE `merge_id` = ? AND `state` = 'ACTIVE'",
                [(int) $admin['principal_id'], $binary],
            );
            // The target cover is deliberately not rewritten here. A cover
            // selected at merge time is stored as a separate PERSON_UPDATE
            // audit event and remains an independently reversible operator
            // choice. Member projection never emits it unless it still
            // belongs to the role-visible target photo set.
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PERSON_MERGE_REVERT',
                'target_type' => 'PERSON_MERGE',
                'target_id' => $mergeId,
                'old_value' => ['state' => 'ACTIVE', 'from' => $sourceId, 'to' => $targetId],
                'new_value' => ['state' => 'REVERTED', 'from' => $sourceId, 'to' => $targetId],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    public function setPhotoRule(
        int $adminUserId,
        string $classPersonId,
        string $classPhotoId,
        string $rule,
        string $reason,
    ): void {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $rule = strtoupper(trim($rule));
        if (!in_array($rule, ['INCLUDE', 'EXCLUDE'], true)) {
            throw new \InvalidArgumentException('class_archive_person_photo_rule_invalid');
        }
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $classPersonId, $classPhotoId, $rule, $reason): void {
            $person = DomainSupport::idToBinary($classPersonId);
            $photo = DomainSupport::idToBinary($classPhotoId);
            $this->requireActivePerson($person, true);
            DomainSupport::requireActivePhoto($repository, $classPhotoId, true);
            $before = $repository->fetchOne(
                'SELECT `rule` FROM `' . DomainSupport::table($repository, 'person_photo_rule')
                . '` WHERE `class_person_id` = ? AND `class_photo_id` = ? FOR UPDATE',
                [$person, $photo],
            );
            $repository->execute(
                'INSERT INTO `' . DomainSupport::table($repository, 'person_photo_rule') . '` '
                . '(`class_person_id`,`class_photo_id`,`rule`,`updated_by_principal_id`,`reason`,`created_at`,`updated_at`) '
                . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                . 'ON DUPLICATE KEY UPDATE `rule`=VALUES(`rule`),`updated_by_principal_id`=VALUES(`updated_by_principal_id`),'
                . '`reason`=VALUES(`reason`),`updated_at`=UTC_TIMESTAMP(6)',
                [$person, $photo, $rule, (int) $admin['principal_id'], $reason],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PERSON_PHOTO_RULE_SET',
                'target_type' => 'PERSON_PHOTO_RULE',
                'target_id' => $classPersonId,
                'old_value' => ['rule' => $before['rule'] ?? null, 'class_photo_id' => $classPhotoId],
                'new_value' => ['rule' => $rule, 'class_photo_id' => $classPhotoId],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    public function clearPhotoRule(int $adminUserId, string $classPersonId, string $classPhotoId, string $reason): void
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $classPersonId, $classPhotoId, $reason): void {
            $changed = $repository->execute(
                'DELETE FROM `' . DomainSupport::table($repository, 'person_photo_rule')
                . '` WHERE `class_person_id` = ? AND `class_photo_id` = ?',
                [DomainSupport::idToBinary($classPersonId), DomainSupport::idToBinary($classPhotoId)],
            );
            if ($changed !== 1) {
                throw new \RuntimeException('class_archive_person_photo_rule_missing');
            }
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PERSON_PHOTO_RULE_CLEAR',
                'target_type' => 'PERSON_PHOTO_RULE',
                'target_id' => $classPersonId,
                'old_value' => ['class_photo_id' => $classPhotoId, 'state' => 'OVERRIDDEN'],
                'new_value' => ['class_photo_id' => $classPhotoId, 'state' => 'INHERITED'],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    public function movePhoto(
        int $adminUserId,
        string $fromClassPersonId,
        string $toClassPersonId,
        string $classPhotoId,
        string $reason,
    ): void {
        $this->movePhotos(
            $adminUserId,
            $fromClassPersonId,
            $toClassPersonId,
            [$classPhotoId],
            $reason,
        );
    }

    /**
     * Apply a person-photo correction as one all-or-nothing transaction.
     * A null target means remove the selected photos from the source person.
     *
     * @param list<string> $classPhotoIds
     */
    public function movePhotos(
        int $adminUserId,
        string $fromClassPersonId,
        ?string $toClassPersonId,
        array $classPhotoIds,
        string $reason,
    ): int {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        DomainSupport::idToBinary($fromClassPersonId);
        if ($toClassPersonId !== null) {
            DomainSupport::idToBinary($toClassPersonId);
        }
        if ($toClassPersonId !== null && hash_equals(strtolower($fromClassPersonId), strtolower($toClassPersonId))) {
            throw new \InvalidArgumentException('class_archive_person_photo_move_same');
        }
        $photoIds = [];
        foreach ($classPhotoIds as $classPhotoId) {
            if (!is_string($classPhotoId)) {
                throw new \InvalidArgumentException('class_archive_person_photo_move_id_invalid');
            }
            DomainSupport::idToBinary($classPhotoId);
            $photoIds[strtolower($classPhotoId)] = true;
        }
        if ($photoIds === [] || count($photoIds) > 500) {
            throw new \InvalidArgumentException('class_archive_person_photo_move_count_invalid');
        }
        ksort($photoIds, SORT_STRING);

        return $this->repository->transaction(function (Repository $repository) use (
            $admin, $fromClassPersonId, $toClassPersonId, $photoIds, $reason,
        ): int {
            $from = DomainSupport::idToBinary($fromClassPersonId);
            $to = $toClassPersonId === null ? null : DomainSupport::idToBinary($toClassPersonId);

            // Lock people in deterministic UUID order so concurrent corrections
            // cannot deadlock or observe only part of the requested move.
            $personIds = [$fromClassPersonId];
            if ($toClassPersonId !== null) {
                $personIds[] = $toClassPersonId;
            }
            sort($personIds, SORT_STRING);
            foreach ($personIds as $personId) {
                $this->requireActivePerson(DomainSupport::idToBinary($personId), true);
            }

            $table = DomainSupport::table($repository, 'person_photo_rule');
            $audit = new Audit($repository);
            foreach (array_keys($photoIds) as $classPhotoId) {
                $photo = DomainSupport::idToBinary($classPhotoId);
                DomainSupport::requireActivePhoto($repository, $classPhotoId, true);
                $beforeRows = $repository->fetchAll(
                    'SELECT `class_person_id`,`rule` FROM `' . $table . '` WHERE `class_photo_id` = ? AND `class_person_id` IN ('
                        . ($to === null ? '?' : '?,?') . ') FOR UPDATE',
                    $to === null ? [$photo, $from] : [$photo, $from, $to],
                );
                $before = [];
                foreach ($beforeRows as $row) {
                    $before[DomainSupport::binaryToId((string) $row['class_person_id'])] = (string) $row['rule'];
                }
                $rules = [[$from, 'EXCLUDE']];
                if ($to !== null) {
                    $rules[] = [$to, 'INCLUDE'];
                }
                foreach ($rules as [$person, $rule]) {
                    $repository->execute(
                        'INSERT INTO `' . $table . '` '
                        . '(`class_person_id`,`class_photo_id`,`rule`,`updated_by_principal_id`,`reason`,`created_at`,`updated_at`) '
                        . 'VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
                        . 'ON DUPLICATE KEY UPDATE `rule`=VALUES(`rule`),`updated_by_principal_id`=VALUES(`updated_by_principal_id`),'
                        . '`reason`=VALUES(`reason`),`updated_at`=UTC_TIMESTAMP(6)',
                        [$person, $photo, $rule, (int) $admin['principal_id'], $reason],
                    );
                }
                $audit->append(DomainSupport::auditActor($admin) + [
                    'action' => 'PERSON_PHOTO_MOVE',
                    'target_type' => 'PERSON_PHOTO_RULE',
                    'target_id' => $classPhotoId,
                    'old_value' => [
                        'from' => $fromClassPersonId,
                        'to' => $toClassPersonId,
                        'rule' => $before[strtolower($fromClassPersonId)] ?? 'INHERITED',
                    ],
                    'new_value' => [
                        'from' => $fromClassPersonId,
                        'to' => $toClassPersonId,
                        'rule' => $toClassPersonId === null ? 'EXCLUDE' : 'MOVED',
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
            }
            return count($photoIds);
        });
    }

    public function resolveProjectionPerson(string $classPersonId): string
    {
        return DomainSupport::binaryToId($this->resolveBinaryProjectionPerson(DomainSupport::idToBinary($classPersonId)));
    }

    /** @param list<string> $classPersonIds @return list<array<string,mixed>> */
    public function rulesForPeople(array $classPersonIds): array
    {
        if ($classPersonIds === [] || count($classPersonIds) > 500) {
            return [];
        }
        $ids = [];
        foreach (array_values(array_unique($classPersonIds)) as $id) {
            $ids[] = DomainSupport::idToBinary($id);
        }
        $rows = $this->repository->fetchAll(
            'SELECT `class_person_id`,`class_photo_id`,`rule` FROM `'
                . DomainSupport::table($this->repository, 'person_photo_rule') . '` WHERE `class_person_id` IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids,
        );
        return array_map(static fn(array $row): array => [
            'class_person_id' => DomainSupport::binaryToId((string) $row['class_person_id']),
            'class_photo_id' => DomainSupport::binaryToId((string) $row['class_photo_id']),
            'rule' => (string) $row['rule'],
        ], $rows);
    }

    /** Admin-only complete overlay state, including the optional roster link. @return array<string,mixed> */
    public function listProjectionState(int $adminUserId): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        $people = $this->repository->fetchAll(
            'SELECT * FROM `' . $this->repository->table('person') . '` ORDER BY `updated_at` DESC LIMIT 2000',
        );
        $merges = $this->repository->fetchAll(
            'SELECT `merge_id`,`source_class_person_id`,`target_class_person_id`,`state`,`created_at`,`reverted_at` '
                . 'FROM `' . DomainSupport::table($this->repository, 'person_merge') . '` ORDER BY `created_at` DESC LIMIT 2000',
        );
        $rules = $this->repository->fetchAll(
            'SELECT `class_person_id`,`class_photo_id`,`rule`,`updated_at` FROM `'
                . DomainSupport::table($this->repository, 'person_photo_rule') . '` ORDER BY `updated_at` DESC LIMIT 5000',
        );
        return [
            'persons' => array_map(fn(array $row): array => $this->hydratePerson($row, true), $people),
            'merges' => array_map(static fn(array $row): array => [
                'merge_id' => DomainSupport::binaryToId((string) $row['merge_id']),
                'source_class_person_id' => DomainSupport::binaryToId((string) $row['source_class_person_id']),
                'target_class_person_id' => DomainSupport::binaryToId((string) $row['target_class_person_id']),
                'state' => (string) $row['state'],
                'created_at' => (string) $row['created_at'],
                'reverted_at' => $row['reverted_at'] === null ? null : (string) $row['reverted_at'],
            ], $merges),
            'rules' => array_map(static fn(array $row): array => [
                'class_person_id' => DomainSupport::binaryToId((string) $row['class_person_id']),
                'class_photo_id' => DomainSupport::binaryToId((string) $row['class_photo_id']),
                'rule' => (string) $row['rule'],
                'updated_at' => (string) $row['updated_at'],
            ], $rules),
        ];
    }

    /**
     * Apply the overlay only to an already policy-approved canonical photo set.
     * INCLUDE can never widen visibility because rules outside visible ids are
     * discarded before aggregation. Optional roster/Immich ids are omitted.
     *
     * @param list<string> $visibleClassPhotoIds
     * @param list<array<string,mixed>> $clusterRows
     * @return list<array<string,mixed>>
     */
    public function projectForVisiblePhotos(
        array $visibleClassPhotoIds,
        array $clusterRows,
        bool $includeHidden = false,
        ?int $adminUserId = null,
    ): array
    {
        if ($includeHidden) {
            DomainSupport::requireSystemAdmin((int) $adminUserId);
        }
        $visible = [];
        foreach ($visibleClassPhotoIds as $id) {
            DomainSupport::idToBinary($id);
            $visible[strtolower($id)] = $id;
        }
        if (count($visible) > 50000) {
            throw new \InvalidArgumentException('class_archive_person_projection_batch_invalid');
        }
        $peopleRows = $this->repository->fetchAll(
            "SELECT * FROM `{$this->repository->table('person')}` WHERE `state` = 'ACTIVE'",
        );
        $people = [];
        foreach ($peopleRows as $row) {
            $person = $this->hydratePerson($row, false);
            $people[$person['class_person_id']] = $person;
        }
        $mergeRows = $this->repository->fetchAll(
            'SELECT `source_class_person_id`,`target_class_person_id` FROM `'
                . DomainSupport::table($this->repository, 'person_merge') . "` WHERE `state` = 'ACTIVE'",
        );
        $merges = [];
        foreach ($mergeRows as $row) {
            $merges[DomainSupport::binaryToId((string) $row['source_class_person_id'])]
                = DomainSupport::binaryToId((string) $row['target_class_person_id']);
        }
        $resolve = static function (string $id) use ($merges): string {
            $seen = [];
            for ($depth = 0; $depth < 32 && isset($merges[$id]); ++$depth) {
                if (isset($seen[$id])) {
                    throw new \RuntimeException('class_archive_person_merge_cycle');
                }
                $seen[$id] = true;
                $id = $merges[$id];
            }
            return $id;
        };
        $photosByPerson = [];
        $rawCoverByPerson = [];
        $sourcesByPerson = [];
        foreach ($clusterRows as $cluster) {
            $source = (string) ($cluster['class_person_id'] ?? '');
            DomainSupport::idToBinary($source);
            $target = $resolve($source);
            if (!isset($people[$target]) || (!$includeHidden && ($people[$target]['visibility'] ?? null) !== 'VISIBLE')) {
                continue;
            }
            $sourcesByPerson[$target][strtolower($source)] = strtolower($source);
            foreach (($cluster['class_photo_ids'] ?? []) as $photoId) {
                if (is_string($photoId) && isset($visible[strtolower($photoId)])) {
                    $photosByPerson[$target][strtolower($photoId)] = $visible[strtolower($photoId)];
                }
            }
            $cover = $cluster['cover_class_photo_id'] ?? null;
            if (is_string($cover) && isset($visible[strtolower($cover)])) {
                $rawCoverByPerson[$target] ??= $visible[strtolower($cover)];
            }
        }
        $rules = $this->repository->fetchAll(
            'SELECT `class_person_id`,`class_photo_id`,`rule` FROM `'
                . DomainSupport::table($this->repository, 'person_photo_rule') . '`',
        );
        foreach ($rules as $row) {
            $personId = $resolve(DomainSupport::binaryToId((string) $row['class_person_id']));
            $photoId = DomainSupport::binaryToId((string) $row['class_photo_id']);
            $key = strtolower($photoId);
            if (!isset($people[$personId]) || (!$includeHidden && ($people[$personId]['visibility'] ?? null) !== 'VISIBLE') || !isset($visible[$key])) {
                continue;
            }
            if (($row['rule'] ?? null) === 'INCLUDE') {
                $photosByPerson[$personId][$key] = $visible[$key];
            } else {
                unset($photosByPerson[$personId][$key]);
            }
        }
        $result = [];
        foreach ($people as $personId => $person) {
            if (!$includeHidden && ($person['visibility'] ?? null) !== 'VISIBLE') {
                continue;
            }
            $photos = array_values($photosByPerson[$personId] ?? []);
            if ($photos === []) {
                continue;
            }
            sort($photos, SORT_STRING);
            $cover = $person['cover_class_photo_id'] ?? null;
            if (!is_string($cover) || !in_array($cover, $photos, true)) {
                $cover = $rawCoverByPerson[$personId] ?? $photos[0];
            }
            if (!in_array($cover, $photos, true)) {
                $cover = $photos[0];
            }
            $result[] = [
                'class_person_id' => $personId,
                'display_name' => $person['display_name'],
                'source_kind' => $person['source_kind'],
                'visibility' => $person['visibility'],
                'cover_class_photo_id' => $cover,
                'class_photo_ids' => $photos,
                'source_class_person_ids' => array_values($sourcesByPerson[$personId] ?? []),
                'photo_count' => count($photos),
            ];
        }
        usort($result, static fn(array $a, array $b): int => strcmp((string) ($a['display_name'] ?? ''), (string) ($b['display_name'] ?? '')) ?: strcmp($a['class_person_id'], $b['class_person_id']));
        return $result;
    }

    private function validateIdentityLink(?int $identityId): void
    {
        if ($identityId === null) {
            return;
        }
        if ($identityId <= 0) {
            throw new \InvalidArgumentException('class_archive_person_identity_invalid');
        }
        $row = $this->repository->fetchOne(
            'SELECT `identity_type`,`state` FROM `' . $this->repository->table('identity') . '` WHERE `id` = ? LIMIT 1',
            [$identityId],
        );
        if ($row === null || ($row['identity_type'] ?? null) !== 'CLASSMATE' || ($row['state'] ?? null) === 'RETIRED') {
            throw new \InvalidArgumentException('class_archive_person_identity_invalid');
        }
    }

    /** @return array<string,mixed> */
    private function requireActivePerson(string $binaryId, bool $forUpdate = false): array
    {
        $row = $this->repository->fetchOne(
            'SELECT * FROM `' . $this->repository->table('person') . '` WHERE `class_person_id` = ?'
                . ($forUpdate ? ' FOR UPDATE' : ' LIMIT 1'),
            [$binaryId],
        );
        if ($row === null || ($row['state'] ?? null) !== 'ACTIVE') {
            throw new \RuntimeException('class_archive_person_not_active');
        }
        return $row;
    }

    private function resolveBinaryProjectionPerson(string $binaryId, bool $forUpdate = false): string
    {
        $seen = [];
        for ($depth = 0; $depth < 32; ++$depth) {
            $key = bin2hex($binaryId);
            if (isset($seen[$key])) {
                throw new \RuntimeException('class_archive_person_merge_cycle');
            }
            $seen[$key] = true;
            $row = $this->repository->fetchOne(
                'SELECT `target_class_person_id` FROM `' . DomainSupport::table($this->repository, 'person_merge') . '` '
                    . "WHERE `source_class_person_id` = ? AND `state` = 'ACTIVE'"
                    . ($forUpdate ? ' FOR UPDATE' : ' LIMIT 1'),
                [$binaryId],
            );
            if ($row === null) {
                return $binaryId;
            }
            $binaryId = (string) $row['target_class_person_id'];
        }
        throw new \RuntimeException('class_archive_person_merge_depth_exceeded');
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function hydratePerson(array $row, bool $includeIdentity): array
    {
        $result = [
            'class_person_id' => DomainSupport::binaryToId((string) $row['class_person_id']),
            'display_name' => $row['display_name'] === null ? null : (string) $row['display_name'],
            'cover_class_photo_id' => $row['manual_cover_class_photo_id'] === null
                ? null : DomainSupport::binaryToId((string) $row['manual_cover_class_photo_id']),
            'source_kind' => (string) $row['source_kind'],
            'visibility' => (string) ($row['visibility'] ?? 'VISIBLE'),
            'state' => (string) $row['state'],
            'lock_version' => (int) ($row['lock_version'] ?? 0),
        ];
        if ($includeIdentity) {
            $result['classmate_identity_id'] = $row['classmate_identity_id'] === null ? null : (int) $row['classmate_identity_id'];
            $result['immich_person_id'] = $row['immich_person_id'] === null ? null : (string) $row['immich_person_id'];
        }
        return $result;
    }
}
