<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use RuntimeException;
use Throwable;

/**
 * Privacy boundary for Anonymous comment authors and hidden system accounts.
 *
 * Piwigo remains the comment store. This presenter replaces only output and
 * request-scoped display values; it never changes Core tables or templates.
 * A photo id is the canonical V1 discussion context because one Piwigo comment
 * belongs to exactly one image even when that image is associated with several
 * albums.
 */
final class AnonymousPresenter
{
    public const CONTEXT_PHOTO = 'PHOTO';
    public const CONTEXT_ALBUM = 'ALBUM';

    private const DOMAIN = "class-archive/anonymous/v1\0";
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    private const MIN_ALIAS_CHARACTERS = 8;
    private const GENERIC_ANONYMOUS_NAME = '匿名账号';
    private const GENERIC_ANONYMOUS_AUTHOR = '匿名成员';
    private const GENERIC_SYSTEM_AUTHOR = '系统管理员';

    private static bool $booted = false;
    private static bool $wsImageInfoOverrideRegistered = false;
    private static bool $wsCommentListOverrideRegistered = false;
    private static ?Repository $repository = null;

    /** @var array<string, array<string, mixed>|null> */
    private static array $hiddenAuthorCache = [];

    /** @var list<array<string, mixed>>|null */
    private static ?array $anonymousCandidates = null;

    /** @var array<int, true>|null */
    private static ?array $hiddenUserIds = null;

    /**
     * Register only supported Piwigo extension points. Calling twice is safe.
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        if (!function_exists('add_event_handler')) {
            throw new RuntimeException('class_identity_event_api_unavailable');
        }

        // Access validates the real principal first (priority 5). The display
        // name is replaced only afterwards and only for this PHP request.
        \add_event_handler('user_init', [self::class, 'sanitizeCurrentAnonymousUser'], 20);
        \add_event_handler('render_comment_author', [self::class, 'renderCommentAuthor'], 5);
        \add_event_handler('loc_end_picture', [self::class, 'rewritePictureComments'], 900);
        \add_event_handler('loc_end_comments', [self::class, 'rewriteCommentsPage'], 900);
        \add_event_handler('loc_end_index', [self::class, 'filterSearchUploaderChoices'], 900);
        \add_event_handler('loc_begin_profile', [self::class, 'guardHiddenPrincipalProfile'], 20);
        \add_event_handler('ws_users_getList', [self::class, 'filterOrdinaryUserDiscovery'], 900);
        \add_event_handler('ws_invoke_allowed', [self::class, 'guardHiddenUserSearch'], 20);
        \add_event_handler('ws_add_methods', [self::class, 'registerWsOverrides'], 900);
        \add_event_handler(
            'class_identity_anonymous_presenter_ready',
            [self::class, 'attestReady'],
            50,
        );

        self::$booted = true;
    }

    /**
     * Second half of CapabilityGuard's two-part anonymous-comment gate.
     * UNKNOWN is DENY: an absent secret, schema problem, unsupported Core or
     * missing WS override can never attest readiness.
     */
    public static function attestReady(mixed $previous): bool
    {
        unset($previous);

        try {
            if (!self::$booted || !self::supportedCoreVersion()) {
                return false;
            }
            if (defined('IN_WS')
                && IN_WS
                && (!self::$wsImageInfoOverrideRegistered || !self::$wsCommentListOverrideRegistered)
            ) {
                return false;
            }

            self::secretForVersion(1);
            foreach (self::anonymousBindings() as $binding) {
                self::assertValidBinding($binding);
                self::secretForVersion((int) $binding['pseudonym_key_version']);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $eventUser */
    public static function sanitizeCurrentAnonymousUser(array $eventUser): void
    {
        unset($eventUser);
        global $user;

        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0) {
            return;
        }

        $context = Access::resolveAuthorizationContext($userId);
        if (($context['role'] ?? null) !== Access::ROLE_ANONYMOUS) {
            return;
        }

        // This protects the menubar, pwg.session.getStatus, comment mail and
        // the Core comment.author snapshot without mutating the Core account.
        $user['username'] = self::GENERIC_ANONYMOUS_NAME;
        $user['email'] = '';
    }

    /**
     * Early generic redaction. The later DTO pass has comment id + photo id
     * and replaces this with the stable context-scoped alias.
     */
    public static function renderCommentAuthor(mixed $author): string
    {
        $author = is_scalar($author) ? (string) $author : '';
        if (
            $author === self::GENERIC_ANONYMOUS_NAME
            || $author === self::GENERIC_ANONYMOUS_AUTHOR
            || $author === self::GENERIC_SYSTEM_AUTHOR
            || preg_match('/\A匿名 [' . self::ALPHABET . ']{8,52}\z/uD', $author) === 1
        ) {
            return $author;
        }

        if (array_key_exists($author, self::$hiddenAuthorCache)) {
            $binding = self::$hiddenAuthorCache[$author];
        } else {
            $binding = self::findHiddenBindingByCoreUsername($author);
            self::$hiddenAuthorCache[$author] = $binding;
        }

        if ($binding === null) {
            return $author;
        }

        return ($binding['principal_type'] ?? null) === Access::PRINCIPAL_SYSTEM_ACCOUNT
            ? self::GENERIC_SYSTEM_AUTHOR
            : self::GENERIC_ANONYMOUS_AUTHOR;
    }

    public static function rewritePictureComments(): void
    {
        global $page;

        $photoId = is_array($page ?? null) ? (int) ($page['image_id'] ?? 0) : 0;
        if ($photoId <= 0) {
            self::denyOutput();
        }

        self::rewriteTemplateComments(
            static fn(array $binding): int => $photoId,
            true,
        );
    }

    public static function rewriteCommentsPage(): void
    {
        self::rewriteTemplateComments(
            static fn(array $binding): int => (int) ($binding['image_id'] ?? 0),
            false,
        );
    }

    /**
     * Remove hidden principals from the ordinary "Added by" search facet.
     * Search remains photo-first; an inaccessible identity is not replaced by
     * a correlatable fake id.
     */
    public static function filterSearchUploaderChoices(): void
    {
        global $template;

        if (!is_object($template) || !method_exists($template, 'get_template_vars')) {
            return;
        }
        if (Access::isActiveSystemAdmin()) {
            return;
        }

        try {
            $choices = $template->get_template_vars('ADDED_BY');
            if (!is_array($choices)) {
                return;
            }
            $hiddenIds = self::hiddenPiwigoUserIds();
            $choices = array_values(array_filter(
                $choices,
                static fn(mixed $row): bool => is_array($row)
                    && !isset($hiddenIds[(int) ($row['added_by_id'] ?? 0)]),
            ));
            $template->assign('ADDED_BY', $choices);
        } catch (Throwable) {
            // A partially rendered search facet could reveal both username and
            // Core id. Suppress the facet on UNKNOWN instead.
            $template->assign('ADDED_BY', []);
        }
    }

    public static function guardHiddenPrincipalProfile(): void
    {
        global $user;

        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        $context = $userId > 0 ? Access::resolveAuthorizationContext($userId) : null;
        if (in_array($context['role'] ?? null, [
            Access::ROLE_ANONYMOUS,
            Access::ROLE_SYSTEM_ADMIN,
        ], true)) {
            self::denyHttp(403);
        }
    }

    /**
     * Defense in depth for any plugin which reuses Core's admin user-list
     * serializer on an ordinary route. Native SYSTEM_ADMIN maintenance keeps
     * its complete technical list.
     *
     * @param mixed $users
     * @return mixed
     */
    public static function filterOrdinaryUserDiscovery($users)
    {
        if (!is_array($users) || Access::isActiveSystemAdmin()) {
            return $users;
        }

        try {
            $hiddenIds = self::hiddenPiwigoUserIds();
            foreach ($users as $key => $row) {
                $id = is_array($row) ? (int) ($row['id'] ?? $key) : (int) $key;
                if (isset($hiddenIds[$id])) {
                    unset($users[$key]);
                }
            }
            return $users;
        } catch (Throwable) {
            // Returning an unfiltered user list is never a safe fallback.
            return [];
        }
    }

    /**
     * Reject guessed hidden uploader ids in the public filtered-search API.
     *
     * @param mixed $allowed
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function guardHiddenUserSearch($allowed, string $methodName, array $params)
    {
        if ($allowed !== true || $methodName !== 'pwg.images.filteredSearch.create') {
            return $allowed;
        }
        if (Access::isActiveSystemAdmin() || !isset($params['added_by'])) {
            return $allowed;
        }

        $ids = is_array($params['added_by']) ? $params['added_by'] : [$params['added_by']];
        try {
            $hidden = self::hiddenPiwigoUserIds();
            foreach ($ids as $id) {
                if (!is_scalar($id) || isset($hidden[(int) $id])) {
                    return self::forbiddenResult();
                }
            }
        } catch (Throwable) {
            return self::forbiddenResult();
        }

        return $allowed;
    }

    /** @param mixed $arguments */
    public static function registerWsOverrides($arguments): void
    {
        $service = is_array($arguments ?? null) ? ($arguments[0] ?? null) : null;
        if (!is_object($service)
            || !method_exists($service, 'hasMethod')
            || !method_exists($service, 'addMethod')
            || !$service->hasMethod('pwg.images.getInfo')
            || !$service->hasMethod('pwg.userComments.getList')
        ) {
            self::$wsImageInfoOverrideRegistered = false;
            self::$wsCommentListOverrideRegistered = false;
            return;
        }

        $service->addMethod(
            'pwg.images.getInfo',
            [self::class, 'wsImagesGetInfo'],
            $service->getMethodSignature('pwg.images.getInfo'),
            $service->getMethodDescription('pwg.images.getInfo'),
            PHPWG_ROOT_PATH . 'include/ws_functions/pwg.images.php',
            $service->getMethodOptions('pwg.images.getInfo'),
        );
        self::$wsImageInfoOverrideRegistered = true;

        $service->addMethod(
            'pwg.userComments.getList',
            [self::class, 'wsUserCommentsGetList'],
            $service->getMethodSignature('pwg.userComments.getList'),
            $service->getMethodDescription('pwg.userComments.getList'),
            PHPWG_ROOT_PATH . 'include/ws_functions/pwg.comments.php',
            $service->getMethodOptions('pwg.userComments.getList'),
        );
        self::$wsCommentListOverrideRegistered = true;
    }

    /**
     * Wrapper around the mature Core method. Only its identity-bearing DTO
     * fields are rewritten; image/album ACL and comment pagination stay Core.
     *
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function wsImagesGetInfo(array $params, object $service)
    {
        if (!function_exists('ws_images_getInfo')) {
            return self::serverErrorResult();
        }

        try {
            $result = \ws_images_getInfo($params, $service);
            if (is_object($result) && is_a($result, 'PwgError')) {
                return $result;
            }

            self::rewriteImageInfoResult($result, (int) ($params['image_id'] ?? 0));
            return $result;
        } catch (Throwable) {
            // Do not serialize the original Core result after a lookup or key
            // error. UNKNOWN must not become a privacy bypass.
            return self::serverErrorResult();
        }
    }

    /**
     * Keep Piwigo's native comment-moderation API, but remove the alternate
     * deanonymization path in its author filter. SYSTEM_ADMIN can moderate by
     * comment id; resolving the underlying Identity must use the audited
     * AnonymousResolutionService instead.
     *
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function wsUserCommentsGetList(array $params, object $service)
    {
        if (!function_exists('ws_userComments_getList')) {
            return self::serverErrorResult();
        }

        try {
            if (isset($params['author_id']) && (int) $params['author_id'] > 0) {
                $hiddenIds = self::hiddenPiwigoUserIds();
                if (isset($hiddenIds[(int) $params['author_id']])) {
                    // Otherwise an administrator could enumerate Core user ids
                    // and use result counts as a deanonymization oracle without
                    // producing the required Audit event.
                    return self::forbiddenResult();
                }
            }
            $result = \ws_userComments_getList($params, $service);
            if (is_object($result) && is_a($result, 'PwgError')) {
                return $result;
            }

            self::rewriteAdminCommentListResult($result);
            return $result;
        } catch (Throwable) {
            return self::serverErrorResult();
        }
    }

    /**
     * Pure HMAC primitive used by unit tests and the runtime key resolver.
     *
     * @param list<array{subject:string,key_version:int}> $collisionCandidates
     */
    public static function deriveAlias(
        string $secret,
        string $contextType,
        int|string $contextId,
        string $pseudonymSubject,
        int $keyVersion = 1,
        array $collisionCandidates = [],
    ): string {
        if (strlen($secret) < 32) {
            throw new RuntimeException('class_identity_pseudonym_secret_unavailable');
        }
        if (strlen($pseudonymSubject) !== 16 || $keyVersion <= 0) {
            throw new RuntimeException('class_identity_pseudonym_subject_invalid');
        }

        [$canonicalType, $canonicalId] = self::canonicalContext($contextType, $contextId);
        $code = self::digestCode($secret, $canonicalType, $canonicalId, $pseudonymSubject);
        $otherCodes = [];
        foreach ($collisionCandidates as $candidate) {
            if (!is_array($candidate)
                || !isset($candidate['subject'], $candidate['key_version'])
                || !is_string($candidate['subject'])
                || strlen($candidate['subject']) !== 16
                || (int) $candidate['key_version'] <= 0
            ) {
                throw new RuntimeException('class_identity_pseudonym_candidate_invalid');
            }
            if (hash_equals($candidate['subject'], $pseudonymSubject)
                && (int) $candidate['key_version'] === $keyVersion
            ) {
                continue;
            }
            $candidateSecret = (int) $candidate['key_version'] === $keyVersion
                ? $secret
                : self::secretForVersion((int) $candidate['key_version']);
            $otherCodes[] = self::digestCode(
                $candidateSecret,
                $canonicalType,
                $canonicalId,
                $candidate['subject'],
            );
        }

        return '匿名 ' . self::uniquePrefix($code, $otherCodes);
    }

    public static function resetForTests(): void
    {
        self::$repository = null;
        self::$hiddenAuthorCache = [];
        self::$anonymousCandidates = null;
        self::$hiddenUserIds = null;
        self::$wsImageInfoOverrideRegistered = false;
        self::$wsCommentListOverrideRegistered = false;
    }

    /** @param callable(array<string, mixed>): int $contextResolver */
    private static function rewriteTemplateComments(
        callable $contextResolver,
        bool $recompileCommentList,
    ): void
    {
        global $template;

        if (!is_object($template) || !method_exists($template, 'get_template_vars')) {
            self::denyOutput();
        }

        try {
            $comments = $template->get_template_vars('comments');
            if (!is_array($comments) || $comments === []) {
                return;
            }
            $commentIds = self::commentIdsFromDtos($comments);
            if ($commentIds === []) {
                return;
            }
            $bindings = self::commentBindings($commentIds);
            foreach ($comments as &$comment) {
                if (!is_array($comment)) {
                    continue;
                }
                $commentId = (int) ($comment['ID'] ?? $comment['id'] ?? 0);
                if (!isset($bindings[$commentId])) {
                    continue;
                }
                $binding = $bindings[$commentId];
                $photoId = $contextResolver($binding);
                $comment['AUTHOR'] = self::displayAuthorForBinding($binding, $photoId);
                unset(
                    $comment['WEBSITE_URL'],
                    $comment['EMAIL'],
                    $comment['USER_ID'],
                    $comment['AUTHOR_ID'],
                    $comment['PROFILE_URL'],
                    $comment['AVATAR'],
                );
            }
            unset($comment);
            $template->assign('comments', $comments);
            if ($recompileCommentList) {
                // picture_comment.inc.php materializes COMMENT_LIST before
                // loc_end_picture. Rebuild that mature Core/theme handle from
                // the sanitized DTO; merely reassigning `comments` is too late
                // and leaves the already-rendered author/link in HTML.
                if (!method_exists($template, 'set_filenames')
                    || !method_exists($template, 'assign_var_from_handle')
                ) {
                    throw new RuntimeException('class_identity_comment_template_api_unavailable');
                }
                $template->set_filenames(['comment_list' => 'comment_list.tpl']);
                $template->assign_var_from_handle('COMMENT_LIST', 'comment_list');
            }
        } catch (Throwable) {
            self::denyOutput();
        }
    }

    /** @param mixed $result */
    private static function rewriteImageInfoResult(&$result, int $photoId): void
    {
        if ($photoId <= 0 || !is_array($result)) {
            throw new RuntimeException('class_identity_comment_context_invalid');
        }

        if (isset($result['image']) && is_object($result['image']) && property_exists($result['image'], '_content')) {
            $image = &$result['image']->_content;
        } else {
            $image = &$result;
        }
        if (!is_array($image)) {
            throw new RuntimeException('class_identity_image_dto_invalid');
        }

        if (isset($image['added_by']) && isset(self::hiddenPiwigoUserIds()[(int) $image['added_by']])) {
            unset($image['added_by']);
        }

        if (isset($image['comment_post'])) {
            $post = &$image['comment_post'];
            if (is_object($post) && property_exists($post, '_content')) {
                $post = &$post->_content;
            }
            if (is_array($post)) {
                $attributesKey = defined('WS_XML_ATTRIBUTES') ? WS_XML_ATTRIBUTES : 'attributes_xml_';
                if (isset($post[$attributesKey]) && is_array($post[$attributesKey])) {
                    $post[$attributesKey]['author'] = self::safeCurrentCommentAuthor();
                } elseif (isset($post['author'])) {
                    $post['author'] = self::safeCurrentCommentAuthor();
                }
            }
            unset($post);
        }

        $comments = &$image['comments'];
        if (is_object($comments) && property_exists($comments, '_content')) {
            $comments = &$comments->_content;
        }
        if (!is_array($comments) || $comments === []) {
            unset($comments);
            return;
        }

        $commentIds = self::commentIdsFromDtos($comments);
        $bindings = self::commentBindings($commentIds);
        foreach ($comments as &$comment) {
            if (!is_array($comment)) {
                continue;
            }
            $commentId = (int) ($comment['id'] ?? $comment['ID'] ?? 0);
            if (!isset($bindings[$commentId])) {
                continue;
            }
            $comment['author'] = self::displayAuthorForBinding($bindings[$commentId], $photoId);
            foreach (['author_id', 'user_id', 'account_id', 'seat_id', 'identity_id', 'profile_url', 'avatar'] as $field) {
                unset($comment[$field]);
            }
        }
        unset($comment, $comments, $image);
    }

    /** @param mixed $result */
    private static function rewriteAdminCommentListResult(&$result): void
    {
        if (!is_array($result)
            || !isset($result['comments'])
            || !is_array($result['comments'])
            || !isset($result['filters'])
            || !is_array($result['filters'])
        ) {
            throw new RuntimeException('class_identity_admin_comment_dto_invalid');
        }

        $commentIds = self::commentIdsFromDtos($result['comments']);
        $bindings = self::commentBindings($commentIds);
        foreach ($result['comments'] as &$comment) {
            if (!is_array($comment)) {
                throw new RuntimeException('class_identity_admin_comment_row_invalid');
            }
            $commentId = (int) ($comment['id'] ?? 0);
            if (!isset($bindings[$commentId])) {
                continue;
            }
            $binding = $bindings[$commentId];
            $comment['author'] = self::displayAuthorForBinding(
                $binding,
                (int) ($binding['image_id'] ?? 0),
            );
            foreach (['author_id', 'user_id', 'account_id', 'seat_id', 'identity_id', 'principal_id', 'author_status'] as $field) {
                unset($comment[$field]);
            }
        }
        unset($comment);

        if (isset($result['filters']['nb_authors'])) {
            if (!is_array($result['filters']['nb_authors'])) {
                throw new RuntimeException('class_identity_admin_comment_filter_invalid');
            }
            $hiddenIds = self::hiddenPiwigoUserIds();
            $result['filters']['nb_authors'] = array_values(array_filter(
                $result['filters']['nb_authors'],
                static function (mixed $row) use ($hiddenIds): bool {
                    if (!is_array($row)) {
                        throw new RuntimeException('class_identity_admin_comment_filter_row_invalid');
                    }
                    return !isset($hiddenIds[(int) ($row['author_id'] ?? 0)]);
                },
            ));
        }
    }

    /** @param list<array<string, mixed>> $comments
     *  @return list<int>
     */
    private static function commentIdsFromDtos(array $comments): array
    {
        $ids = [];
        foreach ($comments as $comment) {
            if (!is_array($comment)) {
                continue;
            }
            $id = (int) ($comment['id'] ?? $comment['ID'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /** @param list<int> $commentIds
     *  @return array<int, array<string, mixed>>
     */
    private static function commentBindings(array $commentIds): array
    {
        if ($commentIds === []) {
            return [];
        }
        if (!defined('COMMENTS_TABLE')) {
            throw new RuntimeException('class_identity_comments_table_unavailable');
        }

        $ids = [];
        foreach ($commentIds as $id) {
            if ($id <= 0) {
                throw new RuntimeException('class_identity_comment_id_invalid');
            }
            $ids[$id] = $id;
        }

        $repository = self::repository();
        $principal = '`' . $repository->table('principal') . '`';
        $account = '`' . $repository->table('account') . '`';
        $seat = '`' . $repository->table('seat') . '`';
        $rows = $repository->fetchAll(
            'SELECT c.`id` AS comment_id, c.`image_id`, c.`author_id`, '
            . 'p.`id` AS principal_id, p.`principal_type`, p.`system_role`, '
            . 'a.`id` AS account_id, a.`pseudonym_key_version`, '
            . 's.`id` AS seat_id, s.`identity_id`, s.`seat_type`, s.`pseudonym_subject` '
            . 'FROM ' . self::coreTable(COMMENTS_TABLE) . ' c '
            . 'INNER JOIN ' . $principal . ' p ON p.`piwigo_user_id` = c.`author_id` '
            . 'LEFT JOIN ' . $account . ' a ON a.`id` = p.`account_id` '
            . 'LEFT JOIN ' . $seat . ' s ON s.`id` = a.`seat_id` '
            . 'WHERE c.`id` IN (' . implode(',', $ids) . ') '
            . "AND (p.`principal_type` = 'SYSTEM_ACCOUNT' OR s.`seat_type` = 'ANONYMOUS')",
        );

        $result = [];
        foreach ($rows as $row) {
            $id = (int) ($row['comment_id'] ?? 0);
            if ($id > 0) {
                $result[$id] = $row;
            }
        }
        return $result;
    }

    /** @param array<string, mixed> $binding */
    private static function displayAuthorForBinding(array $binding, int $photoId): string
    {
        if (($binding['principal_type'] ?? null) === Access::PRINCIPAL_SYSTEM_ACCOUNT) {
            return self::GENERIC_SYSTEM_AUTHOR;
        }
        if (($binding['seat_type'] ?? null) !== Access::ROLE_ANONYMOUS || $photoId <= 0) {
            throw new RuntimeException('class_identity_hidden_author_mapping_invalid');
        }

        self::assertValidBinding($binding);
        $version = (int) $binding['pseudonym_key_version'];
        return self::deriveAlias(
            self::secretForVersion($version),
            self::CONTEXT_PHOTO,
            $photoId,
            (string) $binding['pseudonym_subject'],
            $version,
            self::collisionCandidates(),
        );
    }

    /** @return array<string, mixed>|null */
    private static function findHiddenBindingByCoreUsername(string $username): ?array
    {
        global $conf;

        if ($username === '' || strlen($username) > 100 || !defined('USERS_TABLE')) {
            return null;
        }
        $idField = (string) ($conf['user_fields']['id'] ?? 'id');
        $usernameField = (string) ($conf['user_fields']['username'] ?? 'username');
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $idField)
            || !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $usernameField)
        ) {
            throw new RuntimeException('class_identity_core_user_fields_invalid');
        }

        $repository = self::repository();
        $principal = '`' . $repository->table('principal') . '`';
        $account = '`' . $repository->table('account') . '`';
        $seat = '`' . $repository->table('seat') . '`';
        return $repository->fetchOne(
            'SELECT p.`principal_type`, p.`system_role`, s.`seat_type` '
            . 'FROM ' . self::coreTable(USERS_TABLE) . ' u '
            . 'INNER JOIN ' . $principal . ' p ON p.`piwigo_user_id` = u.`' . $idField . '` '
            . 'LEFT JOIN ' . $account . ' a ON a.`id` = p.`account_id` '
            . 'LEFT JOIN ' . $seat . ' s ON s.`id` = a.`seat_id` '
            . 'WHERE BINARY u.`' . $usernameField . '` = BINARY ? '
            . "AND (p.`principal_type` = 'SYSTEM_ACCOUNT' OR s.`seat_type` = 'ANONYMOUS') LIMIT 1",
            [$username],
        );
    }

    /** @return list<array<string, mixed>> */
    private static function anonymousBindings(): array
    {
        if (self::$anonymousCandidates !== null) {
            return self::$anonymousCandidates;
        }

        $repository = self::repository();
        self::$anonymousCandidates = $repository->fetchAll(
            'SELECT DISTINCT s.`id` AS seat_id, s.`pseudonym_subject`, a.`pseudonym_key_version` '
            . 'FROM `' . $repository->table('seat') . '` s '
            . 'INNER JOIN `' . $repository->table('account') . '` a ON a.`seat_id` = s.`id` '
            . 'INNER JOIN `' . $repository->table('principal') . '` p ON p.`account_id` = a.`id` '
            . "WHERE s.`seat_type` = 'ANONYMOUS'",
        );
        return self::$anonymousCandidates;
    }

    /** @return list<array{subject:string,key_version:int}> */
    private static function collisionCandidates(): array
    {
        $candidates = [];
        foreach (self::anonymousBindings() as $binding) {
            self::assertValidBinding($binding);
            $candidates[] = [
                'subject' => (string) $binding['pseudonym_subject'],
                'key_version' => (int) $binding['pseudonym_key_version'],
            ];
        }
        return $candidates;
    }

    /** @param array<string, mixed> $binding */
    private static function assertValidBinding(array $binding): void
    {
        if (strlen((string) ($binding['pseudonym_subject'] ?? '')) !== 16
            || (int) ($binding['pseudonym_key_version'] ?? 0) <= 0
        ) {
            throw new RuntimeException('class_identity_anonymous_binding_invalid');
        }
    }

    /** @return array<int, true> */
    private static function hiddenPiwigoUserIds(): array
    {
        if (self::$hiddenUserIds !== null) {
            return self::$hiddenUserIds;
        }

        $repository = self::repository();
        $rows = $repository->fetchAll(
            'SELECT p.`piwigo_user_id` FROM `' . $repository->table('principal') . '` p '
            . 'LEFT JOIN `' . $repository->table('account') . '` a ON a.`id` = p.`account_id` '
            . 'LEFT JOIN `' . $repository->table('seat') . '` s ON s.`id` = a.`seat_id` '
            . "WHERE p.`principal_type` = 'SYSTEM_ACCOUNT' OR s.`seat_type` = 'ANONYMOUS'",
        );
        self::$hiddenUserIds = [];
        foreach ($rows as $row) {
            $id = (int) ($row['piwigo_user_id'] ?? 0);
            if ($id > 0) {
                self::$hiddenUserIds[$id] = true;
            }
        }
        return self::$hiddenUserIds;
    }

    private static function safeCurrentCommentAuthor(): string
    {
        global $user;

        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        $context = $userId > 0 ? Access::resolveAuthorizationContext($userId) : null;
        return ($context['role'] ?? null) === Access::ROLE_ANONYMOUS
            ? self::GENERIC_ANONYMOUS_NAME
            : (string) ($user['username'] ?? '');
    }

    /** @return array{0:string,1:string} */
    private static function canonicalContext(string $contextType, int|string $contextId): array
    {
        $contextType = strtoupper(trim($contextType));
        if (!in_array($contextType, [self::CONTEXT_PHOTO, self::CONTEXT_ALBUM], true)) {
            throw new RuntimeException('class_identity_pseudonym_context_type_invalid');
        }

        if (is_int($contextId)) {
            if ($contextId <= 0) {
                throw new RuntimeException('class_identity_pseudonym_context_id_invalid');
            }
            $canonicalId = (string) $contextId;
        } else {
            $canonicalId = trim($contextId);
            if (!preg_match('/\A[0-9]{1,18}\z/D', $canonicalId) || (int) $canonicalId <= 0) {
                throw new RuntimeException('class_identity_pseudonym_context_id_invalid');
            }
            $canonicalId = (string) ((int) $canonicalId);
        }

        return [$contextType, $canonicalId];
    }

    private static function digestCode(
        string $secret,
        string $contextType,
        string $contextId,
        string $subject,
    ): string {
        $digest = hash_hmac(
            'sha256',
            self::DOMAIN . $contextType . "\0" . $contextId . "\0" . $subject,
            $secret,
            true,
        );
        return self::base32($digest);
    }

    private static function base32(string $bytes): string
    {
        $buffer = 0;
        $bits = 0;
        $result = '';
        $length = strlen($bytes);
        for ($index = 0; $index < $length; ++$index) {
            $buffer = ($buffer << 8) | ord($bytes[$index]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $result .= self::ALPHABET[($buffer >> $bits) & 31];
                $buffer &= $bits === 0 ? 0 : (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $result .= self::ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $result;
    }

    /** @param list<string> $otherCodes */
    private static function uniquePrefix(string $code, array $otherCodes): string
    {
        for ($length = self::MIN_ALIAS_CHARACTERS; $length <= strlen($code); ++$length) {
            $prefix = substr($code, 0, $length);
            $collision = false;
            foreach ($otherCodes as $otherCode) {
                if (!is_string($otherCode) || strlen($otherCode) < $length) {
                    throw new RuntimeException('class_identity_pseudonym_collision_input_invalid');
                }
                if (hash_equals($prefix, substr($otherCode, 0, $length))) {
                    $collision = true;
                    break;
                }
            }
            if (!$collision) {
                return $prefix;
            }
        }
        throw new RuntimeException('class_identity_pseudonym_collision_unresolved');
    }

    private static function secretForVersion(int $version): string
    {
        if ($version <= 0) {
            throw new RuntimeException('class_identity_pseudonym_key_version_invalid');
        }

        $name = $version === 1
            ? 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET'
            : 'CLASS_ARCHIVE_ANONYMOUS_PSEUDONYM_SECRET_V' . $version;
        $secret = getenv($name);
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('class_identity_pseudonym_secret_unavailable');
        }
        return $secret;
    }

    private static function supportedCoreVersion(): bool
    {
        return defined('PHPWG_VERSION')
            && version_compare((string) PHPWG_VERSION, '16.4.0', '>=')
            && version_compare((string) PHPWG_VERSION, '16.5.0', '<');
    }

    private static function coreTable(string $table): string
    {
        if (!preg_match('/\A[A-Za-z0-9_]+\z/D', $table)) {
            throw new RuntimeException('class_identity_core_table_invalid');
        }
        return '`' . $table . '`';
    }

    private static function repository(): Repository
    {
        if (self::$repository === null) {
            self::$repository = Repository::fromPiwigo();
        }
        return self::$repository;
    }

    /** @return mixed */
    private static function forbiddenResult()
    {
        return class_exists('PwgError') ? new \PwgError(403, 'Access denied') : false;
    }

    /** @return mixed */
    private static function serverErrorResult()
    {
        return class_exists('PwgError') ? new \PwgError(503, 'Service unavailable') : false;
    }

    private static function denyOutput(): never
    {
        self::denyHttp(503);
    }

    private static function denyHttp(int $status): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $status === 403 ? 'Access denied.' : 'Service unavailable.';
        exit;
    }
}
