<?php

declare(strict_types=1);

/**
 * Synthetic-only runtime fixture for the PhotoComment domain.
 *
 * Execute inside the engineering Piwigo container as the unprivileged nginx
 * user with CLASS_ARCHIVE_ALLOW_PHOTO_COMMENT_RUNTIME=1.  It creates only
 * fresh UUID-keyed comments against two existing synthetic HERITAGE images,
 * verifies the product boundary, and removes each fixture comment and its
 * generated audit rows in finally.  It never reads private-library volumes.
 */

function photoCommentRuntimeFail(string $code): never
{
    fwrite(STDERR, 'PHOTO_COMMENT_RUNTIME=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $code) . "\n");
    exit(1);
}

/** @return never */
function photoCommentRuntimeAssert(bool $condition, string $code): void
{
    if (!$condition) {
        throw new RuntimeException($code);
    }
}

/** @return never */
function photoCommentRuntimeExpect(callable $callback, string $expected, string $code): void
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error->getMessage() === $expected) {
            return;
        }
        throw new RuntimeException($code . '_' . $error->getMessage(), 0, $error);
    }
    throw new RuntimeException($code . '_not_rejected');
}

/** @param mixed $value */
function photoCommentRuntimeNoPrivateKeys(mixed $value): bool
{
    $forbidden = [
        'principal', 'account', 'seat', 'identity', 'pseudonym_subject',
        'piwigo_image_id', 'immich_asset_id', 'source_path', 'absolute_path',
        'user_id', 'classmate_id',
    ];
    if (!is_array($value)) {
        return true;
    }
    foreach ($value as $key => $child) {
        if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
            return false;
        }
        if (!photoCommentRuntimeNoPrivateKeys($child)) {
            return false;
        }
    }
    return true;
}

/** @return array{class_photo_id:string,piwigo_image_id:int} */
function photoCommentRuntimePhoto(array $row): array
{
    $binary = $row['class_photo_id'] ?? null;
    $imageId = (int) ($row['piwigo_image_id'] ?? 0);
    if (!is_string($binary) || strlen($binary) !== 16 || $imageId <= 0) {
        throw new RuntimeException('heritage_fixture_photo_invalid');
    }
    return [
        'class_photo_id' => \ClassIdentity\DomainSupport::binaryToId($binary),
        'piwigo_image_id' => $imageId,
    ];
}

if (PHP_SAPI !== 'cli'
    || getenv('CLASS_ARCHIVE_ALLOW_PHOTO_COMMENT_RUNTIME') !== '1'
    || !function_exists('posix_geteuid')
    || posix_geteuid() === 0
) {
    photoCommentRuntimeFail('explicit_unprivileged_synthetic_runtime_required');
}

$assertions = 0;
$stage = 'startup';
$repository = null;
$fixtureCommentIds = [];
$exit = 0;

try {
    chdir('/var/www/html/piwigo') || throw new RuntimeException('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    $_SERVER['SCRIPT_NAME'] = '/ws.php';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['HTTP_HOST'] = 'localhost';
    $_SERVER['SERVER_PORT'] = '80';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassIdentity/main.inc.php';

    global $prefixeTable, $conf;
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('piwigo_prefix_invalid');
    }
    if (!class_exists(\ClassIdentity\PhotoCommentService::class, false)
        || \ClassIdentity\Schema::CURRENT_VERSION < 15) {
        throw new RuntimeException('photo_comment_domain_not_installed');
    }
    $repository = \ClassIdentity\Repository::fromPiwigo();
    $commentTable = '`' . $repository->table('photo_comment') . '`';
    $auditTable = '`' . $repository->table('audit_event') . '`';
    $photoTable = '`' . $repository->table('photo') . '`';
    $archiveTable = '`' . $repository->table('archive_image') . '`';
    $check = $repository->fetchOne('SELECT COUNT(*) AS `count` FROM ' . $commentTable);
    if ((int) ($check['count'] ?? -1) < 0) {
        throw new RuntimeException('photo_comment_table_unavailable');
    }

    $stage = 'fixture_accounts';
    $fixtureRows = $repository->fetchAll(
        'SELECT `id`,`username` FROM `' . $prefixeTable . 'users` '
        . "WHERE `username` IN ('fixture-classmate','fixture-teacher','fixture-family','fixture-anonymous')",
    );
    $users = [];
    foreach ($fixtureRows as $row) {
        $username = $row['username'] ?? null;
        $id = (int) ($row['id'] ?? 0);
        if (is_string($username) && $id > 0) {
            $users[$username] = $id;
        }
    }
    foreach ([
        'fixture-classmate' => \ClassIdentity\Access::ROLE_CLASSMATE,
        'fixture-teacher' => \ClassIdentity\Access::ROLE_TEACHER,
        'fixture-family' => \ClassIdentity\Access::ROLE_FAMILY,
        'fixture-anonymous' => \ClassIdentity\Access::ROLE_ANONYMOUS,
    ] as $username => $role) {
        photoCommentRuntimeAssert(isset($users[$username]), 'fixture_user_missing_' . substr($username, 8));
        \ClassIdentity\Access::resetRepositoryForTests();
        $context = \ClassIdentity\Access::resolveAuthorizationContext($users[$username]);
        photoCommentRuntimeAssert(is_array($context) && ($context['role'] ?? null) === $role, 'fixture_role_invalid_' . $role);
        ++$assertions;
    }
    $adminUserId = (int) ($conf['webmaster_id'] ?? 0);
    \ClassIdentity\Access::resetRepositoryForTests();
    $adminContext = \ClassIdentity\Access::resolveAuthorizationContext($adminUserId);
    photoCommentRuntimeAssert(
        is_array($adminContext) && ($adminContext['role'] ?? null) === \ClassIdentity\Access::ROLE_SYSTEM_ADMIN,
        'fixture_system_admin_invalid',
    );
    ++$assertions;

    $stage = 'heritage_fixture_photos';
    $heritage = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-heritage' LIMIT 1",
    );
    $living = $repository->fetchOne(
        'SELECT `id` FROM `' . $prefixeTable . "categories` WHERE `permalink`='class-archive-living' LIMIT 1",
    );
    $heritageId = (int) ($heritage['id'] ?? 0);
    $livingId = (int) ($living['id'] ?? 0);
    photoCommentRuntimeAssert($heritageId > 0 && $livingId > 0 && $heritageId !== $livingId, 'era_roots_invalid');
    $photos = $repository->fetchAll(
        'SELECT p.`class_photo_id`,p.`piwigo_image_id` FROM ' . $photoTable . ' p '
        . 'JOIN `' . $prefixeTable . 'image_category` ic ON ic.`image_id`=p.`piwigo_image_id` '
        . 'JOIN `' . $prefixeTable . 'categories` c ON c.`id`=ic.`category_id` '
        . 'LEFT JOIN ' . $archiveTable . ' a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . 'LEFT JOIN ' . $commentTable . ' pc ON pc.`class_photo_id`=p.`class_photo_id` '
        . "WHERE p.`state`='ACTIVE' AND p.`piwigo_image_id` IS NOT NULL AND (a.`era`='HERITAGE' OR a.`era` IS NULL) "
        . 'GROUP BY p.`class_photo_id`,p.`piwigo_image_id` '
        . 'HAVING MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=1 '
        . 'AND MAX(CASE WHEN ic.`category_id`=? OR FIND_IN_SET(?,c.`uppercats`)>0 THEN 1 ELSE 0 END)=0 '
        . 'AND COUNT(pc.`comment_id`)=0 '
        . 'ORDER BY p.`piwigo_image_id` ASC LIMIT 2',
        [$heritageId, $heritageId, $livingId, $livingId],
    );
    photoCommentRuntimeAssert(count($photos) === 2, 'two_heritage_photos_required');
    $first = photoCommentRuntimePhoto($photos[0]);
    $second = photoCommentRuntimePhoto($photos[1]);
    ++$assertions;

    $service = \ClassIdentity\PhotoCommentService::fromPiwigo();
    $marker = 'synthetic-comment-' . bin2hex(random_bytes(8));
    $stage = 'classmate_comment';
    $root = $service->create(
        $users['fixture-classmate'],
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        null,
        $marker . '-classmate',
    );
    $rootId = (string) ($root['comment_id'] ?? '');
    \ClassIdentity\DomainSupport::idToBinary($rootId);
    $fixtureCommentIds[] = $rootId;
    ++$assertions;

    $stage = 'teacher_reply';
    $reply = $service->create(
        $users['fixture-teacher'],
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        $rootId,
        $marker . '-teacher',
    );
    $replyId = (string) ($reply['comment_id'] ?? '');
    \ClassIdentity\DomainSupport::idToBinary($replyId);
    $fixtureCommentIds[] = $replyId;
    ++$assertions;

    $stage = 'anonymous_comment';
    $anonymous = $service->create(
        $users['fixture-anonymous'],
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        null,
        $marker . '-anonymous',
    );
    $anonymousId = (string) ($anonymous['comment_id'] ?? '');
    \ClassIdentity\DomainSupport::idToBinary($anonymousId);
    $fixtureCommentIds[] = $anonymousId;
    ++$assertions;

    $stage = 'bounded_keyset_pagination';
    $firstPage = $service->listForVisiblePhoto(
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        \ClassIdentity\Access::ROLE_CLASSMATE,
        null,
        2,
    );
    photoCommentRuntimeAssert(
        ($firstPage['total'] ?? null) === 3
        && count((array) ($firstPage['items'] ?? [])) === 2
        && ($firstPage['hasMore'] ?? null) === true
        && is_string($firstPage['nextCursor'] ?? null),
        'comment_first_page_invalid',
    );
    $secondPage = $service->listForVisiblePhoto(
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        \ClassIdentity\Access::ROLE_CLASSMATE,
        (string) $firstPage['nextCursor'],
        2,
    );
    photoCommentRuntimeAssert(
        ($secondPage['total'] ?? null) === 3
        && count((array) ($secondPage['items'] ?? [])) === 1
        && ($secondPage['hasMore'] ?? null) === false
        && ($secondPage['nextCursor'] ?? false) === null,
        'comment_second_page_invalid',
    );
    $pagedIds = array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), [
        ...(array) $firstPage['items'],
        ...(array) $secondPage['items'],
    ]);
    photoCommentRuntimeAssert(count(array_unique($pagedIds)) === 3, 'comment_keyset_page_overlap');
    photoCommentRuntimeExpect(
        static fn () => $service->listForVisiblePhoto(
            $first['class_photo_id'],
            $first['piwigo_image_id'],
            \ClassIdentity\Access::ROLE_CLASSMATE,
            null,
            201,
        ),
        'class_archive_comment_page_limit_invalid',
        'comment_page_limit',
    );
    photoCommentRuntimeExpect(
        static fn () => $service->listForVisiblePhoto(
            $second['class_photo_id'],
            $second['piwigo_image_id'],
            \ClassIdentity\Access::ROLE_CLASSMATE,
            (string) $firstPage['nextCursor'],
            2,
        ),
        'class_archive_comment_page_cursor_invalid',
        'comment_cross_photo_cursor',
    );
    ++$assertions;

    $stage = 'family_write_denial';
    photoCommentRuntimeExpect(
        static fn () => $service->create(
            $users['fixture-family'],
            $first['class_photo_id'],
            $first['piwigo_image_id'],
            null,
            $marker . '-forbidden',
        ),
        'class_archive_comment_write_forbidden',
        'family_write',
    );
    ++$assertions;

    $stage = 'cross_photo_reply_denial';
    photoCommentRuntimeExpect(
        static fn () => $service->create(
            $users['fixture-classmate'],
            $second['class_photo_id'],
            $second['piwigo_image_id'],
            $rootId,
            $marker . '-cross-photo',
        ),
        'class_archive_comment_parent_invalid',
        'cross_photo_reply',
    );
    ++$assertions;

    $stage = 'family_read_projection';
    $familyProjection = $service->listForVisiblePhoto(
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        \ClassIdentity\Access::ROLE_FAMILY,
    );
    photoCommentRuntimeAssert(($familyProjection['total'] ?? null) === 3, 'family_comment_projection_count_invalid');
    foreach ((array) ($familyProjection['items'] ?? []) as $item) {
        photoCommentRuntimeAssert(($item['canReply'] ?? null) === false, 'family_comment_reply_capability_leaked');
    }
    ++$assertions;

    $stage = 'public_projection';
    $projection = $service->listForVisiblePhoto(
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        \ClassIdentity\Access::ROLE_CLASSMATE,
    );
    photoCommentRuntimeAssert(($projection['total'] ?? null) === 3, 'comment_projection_count_invalid');
    photoCommentRuntimeAssert(photoCommentRuntimeNoPrivateKeys($projection), 'comment_projection_private_key_leak');
    $encoded = json_encode($projection, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['fixture-classmate', 'fixture-teacher', 'fixture-family', 'fixture-anonymous', $marker] as $forbidden) {
        photoCommentRuntimeAssert(!str_contains($encoded, $forbidden), 'comment_projection_private_value_leak');
    }
    $anonymousItem = null;
    foreach ((array) ($projection['items'] ?? []) as $item) {
        $author = $item['author'] ?? null;
        photoCommentRuntimeAssert(
            is_array($author) && array_keys($author) === ['label', 'kind'],
            'comment_projection_author_shape_invalid',
        );
        if (($author['kind'] ?? null) === 'ANONYMOUS') {
            $anonymousItem = $item;
        }
    }
    photoCommentRuntimeAssert(
        is_array($anonymousItem)
        && ($anonymousItem['author']['kind'] ?? null) === 'ANONYMOUS'
        && is_string($anonymousItem['author']['label'] ?? null)
        && preg_match('/\A匿名\s+[^\s]{1,32}\z/uD', (string) $anonymousItem['author']['label']) === 1,
        'anonymous_public_pseudonym_invalid',
    );
    ++$assertions;

    $stage = 'admin_delete_parent_tombstone';
    $reason = '合成评论审核删除';
    $deleted = $service->delete($adminUserId, $rootId, $reason);
    photoCommentRuntimeAssert(($deleted['deleted'] ?? null) === true, 'admin_comment_delete_failed');
    ++$assertions;
    $afterDelete = $service->listForVisiblePhoto(
        $first['class_photo_id'],
        $first['piwigo_image_id'],
        \ClassIdentity\Access::ROLE_CLASSMATE,
    );
    photoCommentRuntimeAssert(($afterDelete['total'] ?? null) === 3, 'deleted_parent_thread_count_invalid');
    $tombstone = null;
    $survivingReply = null;
    foreach ((array) ($afterDelete['items'] ?? []) as $item) {
        if (($item['id'] ?? null) === $rootId) {
            $tombstone = $item;
        }
        if (($item['id'] ?? null) === $replyId) {
            $survivingReply = $item;
        }
    }
    photoCommentRuntimeAssert(
        is_array($tombstone)
        && ($tombstone['deleted'] ?? null) === true
        && array_key_exists('body', $tombstone)
        && $tombstone['body'] === null
        && ($tombstone['author']['kind'] ?? null) === 'DELETED'
        && ($tombstone['canReply'] ?? null) === false
        && ($tombstone['canDelete'] ?? null) === false,
        'deleted_parent_tombstone_invalid',
    );
    photoCommentRuntimeAssert(
        is_array($survivingReply)
        && ($survivingReply['deleted'] ?? null) === false
        && ($survivingReply['parentId'] ?? null) === $rootId,
        'deleted_parent_reply_not_preserved',
    );
    photoCommentRuntimeAssert(!str_contains(
        json_encode($afterDelete, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $marker . '-classmate',
    ), 'deleted_parent_body_leaked');
    ++$assertions;

    $stage = 'per_principal_burst_limit';
    for ($index = 0; $index < 9; ++$index) {
        $burst = $service->create(
            $users['fixture-classmate'],
            $second['class_photo_id'],
            $second['piwigo_image_id'],
            null,
            $marker . '-burst-' . $index,
        );
        $burstId = (string) ($burst['comment_id'] ?? '');
        \ClassIdentity\DomainSupport::idToBinary($burstId);
        $fixtureCommentIds[] = $burstId;
    }
    photoCommentRuntimeExpect(
        static fn () => $service->create(
            $users['fixture-classmate'],
            $second['class_photo_id'],
            $second['piwigo_image_id'],
            null,
            $marker . '-burst-rejected',
        ),
        'class_archive_comment_rate_limited',
        'classmate_burst_limit',
    );
    ++$assertions;

    $stage = 'audit_redaction';
    $auditRows = $repository->fetchAll(
        'SELECT `id`,`action`,`target_id`,`old_value`,`new_value`,`reason` FROM ' . $auditTable
        . " WHERE `target_type`='PHOTO_COMMENT' AND `target_id` IN (?,?,?) AND `action` IN ('PHOTO_COMMENT_CREATE','PHOTO_COMMENT_DELETE')",
        [$rootId, $replyId, $anonymousId],
    );
    photoCommentRuntimeAssert(count($auditRows) === 4, 'comment_audit_row_count_invalid');
    foreach ($auditRows as $row) {
        $auditId = (int) ($row['id'] ?? 0);
        photoCommentRuntimeAssert($auditId > 0, 'comment_audit_id_invalid');
        $serialized = json_encode([
            'old_value' => $row['old_value'] ?? null,
            'new_value' => $row['new_value'] ?? null,
            'reason' => $row['reason'] ?? null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        photoCommentRuntimeAssert(!str_contains($serialized, $marker), 'comment_audit_body_leak');
        photoCommentRuntimeAssert(!str_contains(strtolower($serialized), 'token'), 'comment_audit_token_leak');
    }
    ++$assertions;
} catch (Throwable $error) {
    $exit = 1;
    fwrite(STDERR, 'PHOTO_COMMENT_RUNTIME=FAIL stage=' . $stage . ' code='
        . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
} finally {
    if ($repository instanceof \ClassIdentity\Repository) {
        try {
            $commentTable = '`' . $repository->table('photo_comment') . '`';
            $auditTable = '`' . $repository->table('audit_event') . '`';
            // Target IDs are freshly generated by this fixture.  Remove their
            // audit rows even if the test failed before it had a chance to
            // collect each audit primary key; a failed security fixture must
            // never leave synthetic mutation evidence in the engineering DB.
            foreach (array_unique($fixtureCommentIds) as $commentId) {
                $repository->execute(
                    'DELETE FROM ' . $auditTable
                    . " WHERE `target_type`='PHOTO_COMMENT' AND `target_id`=? AND `action` IN ('PHOTO_COMMENT_CREATE','PHOTO_COMMENT_DELETE')",
                    [$commentId],
                );
            }
            // Children must disappear before the parent due to the self-FK.
            foreach (array_reverse($fixtureCommentIds) as $commentId) {
                $repository->execute('DELETE FROM ' . $commentTable . ' WHERE `comment_id`=?', [
                    \ClassIdentity\DomainSupport::idToBinary($commentId),
                ]);
            }
            \ClassIdentity\Access::resetRepositoryForTests();
        } catch (Throwable $cleanupError) {
            $exit = 1;
            fwrite(STDERR, 'PHOTO_COMMENT_RUNTIME_CLEANUP=FAIL code='
                . preg_replace('/[^A-Za-z0-9_.-]/', '_', $cleanupError->getMessage()) . "\n");
        }
    }
}

if ($exit === 0) {
    fwrite(STDOUT, 'PHOTO_COMMENT_RUNTIME=PASS assertions=' . $assertions . "\n");
}
exit($exit);
