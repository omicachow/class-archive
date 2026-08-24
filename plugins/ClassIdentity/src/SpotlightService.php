<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/** Server-timed, album-targeted 24-hour Spotlight history. */
final class SpotlightService
{
    private const DURATION = 'P1D';

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /** @return array<string,mixed> */
    public function create(int $userId, string $classAlbumId, string $reason): array
    {
        $owner = DomainSupport::requireMemberRole($userId, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER]);
        $reason = Audit::validateReason($reason, true) ?? '';
        $ownerPrincipalId = (int) $owner['principal_id'];
        $albumBinary = DomainSupport::idToBinary($classAlbumId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $starts = $now->format('Y-m-d H:i:s.u');
        $expires = $now->add(new \DateInterval(self::DURATION))->format('Y-m-d H:i:s.u');

        return $this->repository->transaction(function (Repository $repository) use (
            $owner, $ownerPrincipalId, $classAlbumId, $albumBinary, $starts, $expires, $reason,
        ): array {
            $this->expireDueInTransaction($repository);
            $album = $repository->fetchOne(
                'SELECT `piwigo_category_id`,`album_type`,`owner_principal_id`,`state` FROM `'
                    . DomainSupport::table($repository, 'album') . '` WHERE `class_album_id`=? FOR UPDATE',
                [$albumBinary],
            );
            if ($album === null
                || ($album['album_type'] ?? null) !== 'COMMUNITY'
                || ($album['state'] ?? null) !== 'ACTIVE'
                || (int) ($album['owner_principal_id'] ?? 0) !== $ownerPrincipalId
            ) {
                throw new \RuntimeException('class_archive_spotlight_own_album_required');
            }
            $this->requireMemberVisibleCategory((int) $album['piwigo_category_id'], (int) ($owner['expected_group_id'] ?? 0));
            if ($repository->fetchOne(
                'SELECT `spotlight_id` FROM `' . DomainSupport::table($repository, 'spotlight')
                    . "` WHERE `owner_principal_id`=? AND `state`='ACTIVE' FOR UPDATE",
                [$ownerPrincipalId],
            ) !== null) {
                throw new \RuntimeException('class_archive_spotlight_owner_already_active');
            }
            for ($attempt = 0; $attempt < 3; ++$attempt) {
                $spotlightId = DomainSupport::generateId();
                $binary = DomainSupport::idToBinary($spotlightId);
                if ($repository->fetchOne(
                    'SELECT `spotlight_id` FROM `' . DomainSupport::table($repository, 'spotlight') . '` WHERE `spotlight_id`=? FOR UPDATE',
                    [$binary],
                ) !== null) {
                    continue;
                }
                $repository->execute(
                    'INSERT INTO `' . DomainSupport::table($repository, 'spotlight') . '` '
                    . '(`spotlight_id`,`owner_principal_id`,`class_album_id`,`state`,`starts_at`,`expires_at`,`created_at`,`updated_at`) '
                    . "VALUES (?, ?, ?, 'ACTIVE', ?, ?, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))",
                    [$binary, $ownerPrincipalId, $albumBinary, $starts, $expires],
                );
                (new Audit($repository))->append(DomainSupport::auditActor($owner) + [
                    'action' => 'SPOTLIGHT_CREATE',
                    'target_type' => 'SPOTLIGHT',
                    'target_id' => $spotlightId,
                    'new_value' => [
                        'spotlight_id' => $spotlightId,
                        'class_album_id' => $classAlbumId,
                        'state' => 'ACTIVE',
                        'expires_at' => $expires,
                    ],
                    'reason' => $reason,
                    'result' => 'SUCCESS',
                ]);
                return [
                    'spotlight_id' => $spotlightId,
                    'class_album_id' => $classAlbumId,
                    'state' => 'ACTIVE',
                    'starts_at' => $starts,
                    'expires_at' => $expires,
                ];
            }
            throw new \RuntimeException('class_archive_spotlight_id_collision');
        });
    }

    public function cancel(int $adminUserId, string $spotlightId, string $reason): void
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $reason = Audit::validateReason($reason, true) ?? '';
        $this->repository->transaction(function (Repository $repository) use ($admin, $spotlightId, $reason): void {
            $binary = DomainSupport::idToBinary($spotlightId);
            $row = $repository->fetchOne(
                'SELECT `class_album_id`,`state` FROM `' . DomainSupport::table($repository, 'spotlight')
                    . '` WHERE `spotlight_id`=? FOR UPDATE',
                [$binary],
            );
            if ($row === null || ($row['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_spotlight_not_active');
            }
            $repository->execute(
                'UPDATE `' . DomainSupport::table($repository, 'spotlight') . '` '
                    . "SET `state`='CANCELLED',`cancelled_at`=UTC_TIMESTAMP(6),`cancelled_by_principal_id`=?,`updated_at`=UTC_TIMESTAMP(6) "
                    . "WHERE `spotlight_id`=? AND `state`='ACTIVE'",
                [(int) $admin['principal_id'], $binary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'SPOTLIGHT_CANCEL',
                'target_type' => 'SPOTLIGHT',
                'target_id' => $spotlightId,
                'old_value' => ['state' => 'ACTIVE'],
                'new_value' => ['state' => 'CANCELLED'],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
        });
    }

    /** Expire due rows without trusting a browser-supplied timestamp. */
    public function expireDue(): int
    {
        return $this->repository->transaction(fn(Repository $repository): int => $this->expireDueInTransaction($repository));
    }

    /**
     * Role-safe public active records. The caller must pass album ids already
     * approved by photo/category policy; a missing id yields no Spotlight.
     * Owner principal ids never leave this projection.
     *
     * @param list<string> $visibleClassAlbumIds
     * @return list<array<string,mixed>>
     */
    public function activeForUser(int $userId, array $visibleClassAlbumIds): array
    {
        DomainSupport::requireMemberRole($userId, [
            Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_FAMILY, Access::ROLE_ANONYMOUS,
            Access::ROLE_SYSTEM_ADMIN,
        ]);
        if ($visibleClassAlbumIds === []) {
            return [];
        }
        $ids = [];
        foreach (array_values(array_unique($visibleClassAlbumIds)) as $id) {
            $ids[] = DomainSupport::idToBinary($id);
        }
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('class_archive_spotlight_album_batch_invalid');
        }
        $this->expireDue();
        global $prefixeTable;
        $rows = $this->repository->fetchAll(
            'SELECT s.`spotlight_id`,s.`class_album_id`,s.`starts_at`,s.`expires_at`,a.`piwigo_category_id`,a.`album_type`,a.`era`,c.`name` '
                . 'FROM `' . DomainSupport::table($this->repository, 'spotlight') . '` s '
                . 'JOIN `' . DomainSupport::table($this->repository, 'album') . '` a ON a.`class_album_id`=s.`class_album_id` '
                . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=a.`piwigo_category_id` '
                . "WHERE s.`state`='ACTIVE' AND s.`expires_at` > UTC_TIMESTAMP(6) AND a.`state`='ACTIVE' "
                . 'AND s.`class_album_id` IN (' . implode(',', array_fill(0, count($ids), '?')) . ') '
                . 'ORDER BY s.`starts_at` DESC',
            $ids,
        );
        return array_map(static fn(array $row): array => [
            'spotlight_id' => DomainSupport::binaryToId((string) $row['spotlight_id']),
            'class_album_id' => DomainSupport::binaryToId((string) $row['class_album_id']),
            'piwigo_category_id' => (int) $row['piwigo_category_id'],
            'album_type' => (string) $row['album_type'],
            'era' => (string) $row['era'],
            'name' => (string) $row['name'],
            'starts_at' => (string) $row['starts_at'],
            'expires_at' => (string) $row['expires_at'],
        ], $rows);
    }

    /** Admin history retains owner for governance. @return list<array<string,mixed>> */
    public function history(int $adminUserId, int $limit = 200): array
    {
        DomainSupport::requireSystemAdmin($adminUserId);
        $limit = max(1, min(1000, $limit));
        $rows = $this->repository->fetchAll(
            'SELECT `spotlight_id`,`owner_principal_id`,`class_album_id`,`state`,`starts_at`,`expires_at`,`cancelled_at`,`cancelled_by_principal_id` '
                . 'FROM `' . DomainSupport::table($this->repository, 'spotlight') . '` ORDER BY `created_at` DESC LIMIT ' . $limit,
        );
        return array_map(static fn(array $row): array => [
            'spotlight_id' => DomainSupport::binaryToId((string) $row['spotlight_id']),
            'owner_principal_id' => (int) $row['owner_principal_id'],
            'class_album_id' => DomainSupport::binaryToId((string) $row['class_album_id']),
            'state' => (string) $row['state'],
            'starts_at' => (string) $row['starts_at'],
            'expires_at' => (string) $row['expires_at'],
            'cancelled_at' => $row['cancelled_at'] === null ? null : (string) $row['cancelled_at'],
            'cancelled_by_principal_id' => $row['cancelled_by_principal_id'] === null ? null : (int) $row['cancelled_by_principal_id'],
        ], $rows);
    }

    private function expireDueInTransaction(Repository $repository): int
    {
        $rows = $repository->fetchAll(
            'SELECT `spotlight_id` FROM `' . DomainSupport::table($repository, 'spotlight')
                . "` WHERE `state`='ACTIVE' AND `expires_at` <= UTC_TIMESTAMP(6) FOR UPDATE",
        );
        if ($rows === []) {
            return 0;
        }
        $changed = $repository->execute(
            'UPDATE `' . DomainSupport::table($repository, 'spotlight')
                . "` SET `state`='EXPIRED',`updated_at`=UTC_TIMESTAMP(6) WHERE `state`='ACTIVE' AND `expires_at` <= UTC_TIMESTAMP(6)",
        );
        $audit = new Audit($repository);
        foreach ($rows as $row) {
            $audit->append([
                'actor_kind' => 'SYSTEM',
                'action' => 'SPOTLIGHT_EXPIRE',
                'target_type' => 'SPOTLIGHT',
                'target_id' => DomainSupport::binaryToId((string) $row['spotlight_id']),
                'old_value' => ['state' => 'ACTIVE'],
                'new_value' => ['state' => 'EXPIRED'],
                'result' => 'SUCCESS',
            ]);
        }
        return $changed;
    }

    private function requireMemberVisibleCategory(int $categoryId, int $expectedGroupId): void
    {
        global $prefixeTable;
        if ($categoryId <= 0 || $expectedGroupId <= 0) {
            throw new \RuntimeException('class_archive_spotlight_album_not_member_visible');
        }
        $row = $this->repository->fetchOne(
            'SELECT c.`id` FROM `' . $prefixeTable . 'categories` c '
                . 'JOIN `' . $prefixeTable . 'group_access` ga ON ga.`group_id`=? AND FIND_IN_SET(ga.`cat_id`,c.`uppercats`) > 0 '
                . "WHERE c.`id`=? AND c.`status`='private' AND c.`visible`='true' LIMIT 1",
            [$expectedGroupId, $categoryId],
        );
        if ($row === null) {
            throw new \RuntimeException('class_archive_spotlight_album_not_member_visible');
        }
    }
}
