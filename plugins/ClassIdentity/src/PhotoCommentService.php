<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Thin Class Archive discussion domain for the photo product.
 *
 * Piwigo remains the image and access-control substrate, but its flat Core
 * comment surface is intentionally not re-enabled for members.  This domain
 * owns threaded product comments, with all target-photo authorization still
 * performed by GatewayService before a public DTO is requested.
 */
final class PhotoCommentService
{
    private const MAX_BODY_CHARACTERS = 2000;
    private const DEFAULT_PAGE_LIMIT = 100;
    private const MAX_PAGE_LIMIT = 200;
    private const MAX_WRITES_PER_MINUTE = 10;
    private const MAX_ACTIVE_COMMENTS_PER_PHOTO = 10000;

    public function __construct(private readonly Repository $repository)
    {
    }

    public static function fromPiwigo(): self
    {
        return new self(Repository::fromPiwigo());
    }

    /**
     * Returns only a browser-safe comment DTO.  No principal, account, seat,
     * identity, source-path, or anonymous binding identifier crosses this
     * boundary.
     *
     * @return array{total:int,items:list<array<string,mixed>>,hasMore:bool,nextCursor:?string}
     */
    public function listForVisiblePhoto(
        string $classPhotoId,
        int $piwigoImageId,
        string $viewerRole,
        ?string $cursor = null,
        ?int $limit = null,
    ): array
    {
        $photoBinary = DomainSupport::idToBinary($classPhotoId);
        if ($piwigoImageId <= 0 || !in_array($viewerRole, [
            Access::ROLE_SYSTEM_ADMIN,
            Access::ROLE_CLASSMATE,
            Access::ROLE_TEACHER,
            Access::ROLE_FAMILY,
            Access::ROLE_ANONYMOUS,
        ], true)) {
            throw new \RuntimeException('class_archive_comment_projection_context_invalid');
        }
        $limit ??= self::DEFAULT_PAGE_LIMIT;
        if ($limit < 1 || $limit > self::MAX_PAGE_LIMIT) {
            throw new \InvalidArgumentException('class_archive_comment_page_limit_invalid');
        }

        $comment = DomainSupport::table($this->repository, 'photo_comment');
        $principal = $this->repository->table('principal');
        $account = $this->repository->table('account');
        $seat = $this->repository->table('seat');
        // A deleted parent remains as a body-less tombstone while it has an
        // active direct reply.  That preserves thread context without ever
        // returning the moderated text.  Fully deleted branches disappear.
        $visible = "(c.`state`='ACTIVE' OR (c.`state`='DELETED' AND EXISTS ("
            . 'SELECT 1 FROM `' . $comment . '` child '
            . "WHERE child.`parent_comment_id`=c.`comment_id` AND child.`state`='ACTIVE'"
            . ')))';
        $totalRow = $this->repository->fetchOne(
            'SELECT COUNT(*) AS `count` FROM `' . $comment . '` c '
            . 'WHERE c.`class_photo_id`=? AND ' . $visible,
            [$photoBinary],
        );
        if ($totalRow === null || !isset($totalRow['count']) || (int) $totalRow['count'] < 0) {
            throw new \RuntimeException('class_archive_comment_total_unavailable');
        }
        $total = (int) $totalRow['count'];

        $cursorCreatedAt = null;
        $cursorBinary = null;
        if ($cursor !== null) {
            $cursorBinary = DomainSupport::idToBinary($cursor);
            $cursorRow = $this->repository->fetchOne(
                'SELECT c.`created_at`,c.`comment_id` FROM `' . $comment . '` c '
                . 'WHERE c.`comment_id`=? AND c.`class_photo_id`=? AND ' . $visible . ' LIMIT 1',
                [$cursorBinary, $photoBinary],
            );
            if ($cursorRow === null
                || !is_string($cursorRow['created_at'] ?? null)
                || !is_string($cursorRow['comment_id'] ?? null)
                || strlen((string) $cursorRow['comment_id']) !== 16
            ) {
                throw new \InvalidArgumentException('class_archive_comment_page_cursor_invalid');
            }
            $cursorCreatedAt = (string) $cursorRow['created_at'];
        }

        $parameters = [$photoBinary];
        $afterCursor = '';
        if ($cursorCreatedAt !== null && $cursorBinary !== null) {
            $afterCursor = ' AND (c.`created_at`>? OR (c.`created_at`=? AND c.`comment_id`>?))';
            array_push($parameters, $cursorCreatedAt, $cursorCreatedAt, $cursorBinary);
        }
        $parameters[] = $limit + 1;
        $rows = $this->repository->fetchAll(
            "SELECT c.`comment_id`,c.`class_photo_id`,c.`parent_comment_id`,c.`author_principal_id`,c.`author_role`,CASE WHEN c.`state`='ACTIVE' THEN c.`body` ELSE NULL END AS `body`,c.`state`,c.`created_at`, "
            . 'p.`principal_type`,p.`system_role`,a.`pseudonym_key_version`,s.`seat_type`,s.`pseudonym_subject` '
            . 'FROM `' . $comment . '` c '
            . 'INNER JOIN `' . $principal . '` p ON p.`id`=c.`author_principal_id` '
            . 'LEFT JOIN `' . $account . '` a ON a.`id`=p.`account_id` '
            . 'LEFT JOIN `' . $seat . '` s ON s.`id`=a.`seat_id` '
            . 'WHERE c.`class_photo_id`=? AND ' . $visible . $afterCursor . ' '
            . 'ORDER BY c.`created_at` ASC,c.`comment_id` ASC LIMIT ?',
            $parameters,
        );
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $collisions = $this->anonymousCollisionCandidates();
        $items = [];
        foreach ($rows as $row) {
            $id = DomainSupport::binaryToId((string) ($row['comment_id'] ?? ''));
            $parent = $row['parent_comment_id'] ?? null;
            if ($parent !== null && !is_string($parent)) {
                throw new \RuntimeException('class_archive_comment_parent_invalid');
            }
            $createdAt = $row['created_at'] ?? null;
            if (!is_string($createdAt) || trim($createdAt) === '') {
                throw new \RuntimeException('class_archive_comment_timestamp_invalid');
            }
            $state = $row['state'] ?? null;
            if ($state === 'DELETED') {
                $items[] = [
                    'id' => $id,
                    'parentId' => $parent === null ? null : DomainSupport::binaryToId($parent),
                    'body' => null,
                    'author' => ['label' => '评论已删除', 'kind' => 'DELETED'],
                    'createdAt' => self::publicTimestamp($createdAt),
                    'canReply' => false,
                    'canDelete' => false,
                    'deleted' => true,
                ];
                continue;
            }
            if ($state !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_comment_state_invalid');
            }
            $author = $this->publicAuthor($row, $piwigoImageId, $collisions);
            $body = $row['body'] ?? null;
            if (!is_string($body) || DomainSupport::boundedText($body, self::MAX_BODY_CHARACTERS, true) !== $body) {
                throw new \RuntimeException('class_archive_comment_body_invalid');
            }
            $items[] = [
                'id' => $id,
                'parentId' => $parent === null ? null : DomainSupport::binaryToId($parent),
                'body' => $body,
                'author' => $author,
                'createdAt' => self::publicTimestamp($createdAt),
                'canReply' => self::roleMayWrite($viewerRole),
                'canDelete' => $viewerRole === Access::ROLE_SYSTEM_ADMIN,
                'deleted' => false,
            ];
        }
        $nextCursor = null;
        if ($hasMore) {
            $last = $items[count($items) - 1] ?? null;
            $nextCursor = is_array($last) && is_string($last['id'] ?? null) ? $last['id'] : null;
            if ($nextCursor === null) {
                throw new \RuntimeException('class_archive_comment_page_cursor_unavailable');
            }
        }
        return ['total' => $total, 'items' => $items, 'hasMore' => $hasMore, 'nextCursor' => $nextCursor];
    }

    /**
     * @return array{comment_id:string}
     */
    public function create(
        int $userId,
        string $classPhotoId,
        int $expectedPiwigoImageId,
        ?string $parentCommentId,
        string $body,
    ): array {
        $context = Access::resolveAuthorizationContext($userId);
        $role = is_array($context) ? (string) ($context['role'] ?? '') : '';
        if (!self::roleMayWrite($role) || (int) ($context['principal_id'] ?? 0) <= 0) {
            throw new \RuntimeException('class_archive_comment_write_forbidden');
        }
        if ($expectedPiwigoImageId <= 0) {
            throw new \RuntimeException('class_archive_comment_photo_context_invalid');
        }
        DomainSupport::idToBinary($classPhotoId);
        $parentBinary = $parentCommentId === null ? null : DomainSupport::idToBinary($parentCommentId);
        $body = DomainSupport::boundedText($body, self::MAX_BODY_CHARACTERS, true) ?? '';

        return $this->repository->transaction(function (Repository $repository) use (
            $context, $role, $classPhotoId, $expectedPiwigoImageId, $parentCommentId, $parentBinary, $body,
        ): array {
            $photo = DomainSupport::requireActivePhoto($repository, $classPhotoId, true);
            if ((int) ($photo['piwigo_image_id'] ?? 0) !== $expectedPiwigoImageId) {
                throw new \RuntimeException('class_archive_comment_photo_context_changed');
            }
            $comment = DomainSupport::table($repository, 'photo_comment');
            $principal = $repository->table('principal');
            $principalRow = $repository->fetchOne(
                'SELECT `id`,`state` FROM `' . $principal . '` WHERE `id`=? FOR UPDATE',
                [(int) $context['principal_id']],
            );
            if ($principalRow === null || ($principalRow['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_comment_author_unavailable');
            }
            $recent = $repository->fetchOne(
                'SELECT COUNT(*) AS `count` FROM `' . $comment . '` '
                . 'WHERE `author_principal_id`=? AND `created_at`>=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE',
                [(int) $context['principal_id']],
            );
            if ($recent === null || !isset($recent['count']) || (int) $recent['count'] < 0) {
                throw new \RuntimeException('class_archive_comment_rate_state_unavailable');
            }
            if ((int) $recent['count'] >= self::MAX_WRITES_PER_MINUTE) {
                throw new \RuntimeException('class_archive_comment_rate_limited');
            }
            $active = $repository->fetchOne(
                'SELECT COUNT(*) AS `count` FROM `' . $comment . '` WHERE `class_photo_id`=? AND `state`=\'ACTIVE\'',
                [(string) $photo['class_photo_id']],
            );
            if ($active === null || !isset($active['count']) || (int) $active['count'] < 0) {
                throw new \RuntimeException('class_archive_comment_capacity_state_unavailable');
            }
            if ((int) $active['count'] >= self::MAX_ACTIVE_COMMENTS_PER_PHOTO) {
                throw new \RuntimeException('class_archive_comment_capacity_reached');
            }
            if ($parentBinary !== null) {
                $parent = $repository->fetchOne(
                    'SELECT `class_photo_id`,`state` FROM `' . $comment . '` WHERE `comment_id`=? FOR UPDATE',
                    [$parentBinary],
                );
                if ($parent === null || ($parent['state'] ?? null) !== 'ACTIVE'
                    || !is_string($parent['class_photo_id'] ?? null)
                    || !hash_equals((string) $photo['class_photo_id'], (string) $parent['class_photo_id'])
                ) {
                    // Parent existence and same-photo membership are checked
                    // together so the browser never receives a cross-photo
                    // reply oracle.
                    throw new \InvalidArgumentException('class_archive_comment_parent_invalid');
                }
            }
            $commentId = DomainSupport::generateId();
            $repository->execute(
                'INSERT INTO `' . $comment . '` ('
                . '`comment_id`,`class_photo_id`,`parent_comment_id`,`author_principal_id`,`author_role`,`body`,`state`,`created_at`,`updated_at`'
                . ") VALUES (?,?,?,?,?,?,'ACTIVE',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",
                [
                    DomainSupport::idToBinary($commentId),
                    (string) $photo['class_photo_id'],
                    $parentBinary,
                    (int) $context['principal_id'],
                    $role,
                    $body,
                ],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($context) + [
                'action' => 'PHOTO_COMMENT_CREATE',
                'target_type' => 'PHOTO_COMMENT',
                'target_id' => $commentId,
                'new_value' => [
                    'comment_id' => $commentId,
                    'class_photo_id' => strtolower($classPhotoId),
                    'parent_comment_id' => $parentCommentId === null ? null : strtolower($parentCommentId),
                    'role_code' => $role,
                    'state' => 'ACTIVE',
                ],
                // Deliberately do not audit comment text.
                'reason' => null,
                'result' => 'SUCCESS',
            ]);
            return ['comment_id' => $commentId];
        });
    }

    /** @return array{deleted:bool} */
    public function delete(int $adminUserId, string $commentId, string $reason): array
    {
        $admin = DomainSupport::requireSystemAdmin($adminUserId);
        $binary = DomainSupport::idToBinary($commentId);
        $reason = Audit::validateReason($reason, true) ?? '';
        return $this->repository->transaction(function (Repository $repository) use ($admin, $binary, $commentId, $reason): array {
            $commentTable = DomainSupport::table($repository, 'photo_comment');
            $row = $repository->fetchOne(
                'SELECT `class_photo_id`,`parent_comment_id`,`author_role`,`state` FROM `' . $commentTable . '` WHERE `comment_id`=? FOR UPDATE',
                [$binary],
            );
            if ($row === null) {
                throw new \RuntimeException('class_archive_comment_not_found');
            }
            if (($row['state'] ?? null) !== 'ACTIVE') {
                throw new \RuntimeException('class_archive_comment_already_deleted');
            }
            if (!is_string($row['class_photo_id'] ?? null)) {
                throw new \RuntimeException('class_archive_comment_photo_invalid');
            }
            $repository->execute(
                'UPDATE `' . $commentTable . '` SET `state`=\'DELETED\',`deleted_by_principal_id`=?,`delete_reason`=?,'
                . '`deleted_at`=UTC_TIMESTAMP(6),`updated_at`=UTC_TIMESTAMP(6) WHERE `comment_id`=? AND `state`=\'ACTIVE\'',
                [(int) $admin['principal_id'], $reason, $binary],
            );
            (new Audit($repository))->append(DomainSupport::auditActor($admin) + [
                'action' => 'PHOTO_COMMENT_DELETE',
                'target_type' => 'PHOTO_COMMENT',
                'target_id' => strtolower($commentId),
                'old_value' => [
                    'class_photo_id' => DomainSupport::binaryToId((string) $row['class_photo_id']),
                    'parent_comment_id' => ($row['parent_comment_id'] ?? null) === null
                        ? null : DomainSupport::binaryToId((string) $row['parent_comment_id']),
                    'role_code' => (string) ($row['author_role'] ?? ''),
                    'state' => 'ACTIVE',
                ],
                'new_value' => ['state' => 'DELETED'],
                'reason' => $reason,
                'result' => 'SUCCESS',
            ]);
            return ['deleted' => true];
        });
    }

    private static function roleMayWrite(string $role): bool
    {
        return in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_ANONYMOUS], true);
    }

    private static function publicTimestamp(string $value): string
    {
        // Class Archive stores UTC DATETIME(6); normalize it to an explicit
        // ISO instant so a browser never guesses the server's local timezone.
        if (preg_match('/\A(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})(\.\d{1,6})?\z/D', $value, $parts) !== 1) {
            throw new \RuntimeException('class_archive_comment_timestamp_invalid');
        }
        return $parts[1] . 'T' . $parts[2] . ($parts[3] ?? '') . 'Z';
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array{subject:string,key_version:int}> $collisions
     * @return array{label:string,kind:string}
     */
    private function publicAuthor(array $row, int $piwigoImageId, array $collisions): array
    {
        $role = (string) ($row['author_role'] ?? '');
        $principalType = (string) ($row['principal_type'] ?? '');
        $seatType = $row['seat_type'] ?? null;
        $systemRole = $row['system_role'] ?? null;
        if (!is_string($seatType) && $seatType !== null) {
            throw new \RuntimeException('class_archive_comment_author_invalid');
        }
        if (!is_string($systemRole) && $systemRole !== null) {
            throw new \RuntimeException('class_archive_comment_author_invalid');
        }
        return match ($role) {
            Access::ROLE_CLASSMATE => $this->safeSeatAuthor($principalType, $seatType, Access::ROLE_CLASSMATE, '班级成员', 'CLASSMATE'),
            Access::ROLE_TEACHER => $this->safeSeatAuthor($principalType, $seatType, Access::ROLE_TEACHER, '老师', 'TEACHER'),
            Access::ROLE_ANONYMOUS => $this->safeAnonymousAuthor($row, $piwigoImageId, $collisions),
            Access::ROLE_SYSTEM_ADMIN => ($principalType === Access::PRINCIPAL_SYSTEM_ACCOUNT
                && $systemRole === Access::ROLE_SYSTEM_ADMIN)
                ? ['label' => '管理员', 'kind' => 'SYSTEM_ADMIN']
                : throw new \RuntimeException('class_archive_comment_author_invalid'),
            default => throw new \RuntimeException('class_archive_comment_author_invalid'),
        };
    }

    /** @return array{label:string,kind:string} */
    private function safeSeatAuthor(string $principalType, ?string $seatType, string $expectedRole, string $label, string $kind): array
    {
        if ($principalType !== Access::PRINCIPAL_SEAT_ACCOUNT || $seatType !== $expectedRole) {
            throw new \RuntimeException('class_archive_comment_author_invalid');
        }
        return ['label' => $label, 'kind' => $kind];
    }

    /**
     * @param array<string,mixed> $row
     * @param list<array{subject:string,key_version:int}> $collisions
     * @return array{label:string,kind:string}
     */
    private function safeAnonymousAuthor(array $row, int $piwigoImageId, array $collisions): array
    {
        if (($row['principal_type'] ?? null) !== Access::PRINCIPAL_SEAT_ACCOUNT
            || ($row['seat_type'] ?? null) !== Access::ROLE_ANONYMOUS
            || !is_string($row['pseudonym_subject'] ?? null)
            || strlen((string) $row['pseudonym_subject']) !== 16
            || (int) ($row['pseudonym_key_version'] ?? 0) <= 0
        ) {
            throw new \RuntimeException('class_archive_comment_anonymous_binding_invalid');
        }
        return [
            'label' => AnonymousPresenter::displayAliasForPhotoContext(
                $piwigoImageId,
                (string) $row['pseudonym_subject'],
                (int) $row['pseudonym_key_version'],
                $collisions,
            ),
            'kind' => 'ANONYMOUS',
        ];
    }

    /** @return list<array{subject:string,key_version:int}> */
    private function anonymousCollisionCandidates(): array
    {
        $account = $this->repository->table('account');
        $seat = $this->repository->table('seat');
        $principal = $this->repository->table('principal');
        $rows = $this->repository->fetchAll(
            'SELECT DISTINCT s.`pseudonym_subject`,a.`pseudonym_key_version` '
            . 'FROM `' . $seat . '` s '
            . 'INNER JOIN `' . $account . '` a ON a.`seat_id`=s.`id` '
            . 'INNER JOIN `' . $principal . '` p ON p.`account_id`=a.`id` '
            . "WHERE s.`seat_type`='ANONYMOUS'",
        );
        $result = [];
        foreach ($rows as $row) {
            $subject = $row['pseudonym_subject'] ?? null;
            $version = (int) ($row['pseudonym_key_version'] ?? 0);
            if (!is_string($subject) || strlen($subject) !== 16 || $version <= 0) {
                throw new \RuntimeException('class_archive_comment_anonymous_binding_invalid');
            }
            $result[] = ['subject' => $subject, 'key_version' => $version];
        }
        return $result;
    }
}
