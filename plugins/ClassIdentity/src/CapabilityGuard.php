<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use Throwable;

/**
 * Coarse business-capability boundary for Piwigo write surfaces.
 *
 * This guard does not replace Piwigo's album/image ownership and CSRF checks.
 * It runs before them and rejects roles which must never reach those code
 * paths. An ALLOW here only means "continue to the mature Core/plugin policy".
 */
final class CapabilityGuard
{
    public const COMMENT_IMAGE = 'COMMENT_IMAGE';
    public const RATE_IMAGE = 'RATE_IMAGE';
    public const UPLOAD_PHOTO = 'UPLOAD_PHOTO';
    public const MANAGE_PHOTO = 'MANAGE_PHOTO';
    public const CREATE_ALBUM = 'CREATE_ALBUM';
    public const MANAGE_ALBUM = 'MANAGE_ALBUM';
    public const MANAGE_TAG = 'MANAGE_TAG';
    public const PRIVATE_COLLECTION = 'PRIVATE_COLLECTION';
    public const ACCOUNT_PREFERENCE = 'ACCOUNT_PREFERENCE';

    /** @var array<string, string> */
    private const WS_CAPABILITIES = [
        'pwg.images.addComment' => self::COMMENT_IMAGE,
        'pwg.userComments.delete' => self::COMMENT_IMAGE,
        'pwg.userComments.validate' => self::COMMENT_IMAGE,
        'pwg.images.rate' => self::RATE_IMAGE,
        'pwg.rates.delete' => self::RATE_IMAGE,

        // Core upload methods plus Community's completion callback. Chunk and
        // preflight endpoints are included because some of them create files
        // or disclose an otherwise unavailable upload workflow.
        'pwg.images.addChunk' => self::UPLOAD_PHOTO,
        'pwg.images.addFile' => self::UPLOAD_PHOTO,
        'pwg.images.add' => self::UPLOAD_PHOTO,
        'pwg.images.addSimple' => self::UPLOAD_PHOTO,
        'pwg.images.upload' => self::UPLOAD_PHOTO,
        'pwg.images.uploadAsync' => self::UPLOAD_PHOTO,
        'pwg.images.checkFiles' => self::UPLOAD_PHOTO,
        'pwg.images.checkUpload' => self::UPLOAD_PHOTO,
        'pwg.images.uploadCompleted' => self::UPLOAD_PHOTO,
        'community.images.uploadCompleted' => self::UPLOAD_PHOTO,

        'pwg.images.setPrivacyLevel' => self::MANAGE_PHOTO,
        'pwg.images.formats.delete' => self::MANAGE_PHOTO,
        'pwg.images.setRank' => self::MANAGE_PHOTO,
        'pwg.images.setCategory' => self::MANAGE_PHOTO,
        'pwg.images.delete' => self::MANAGE_PHOTO,
        'pwg.images.setMd5sum' => self::MANAGE_PHOTO,
        'pwg.images.syncMetadata' => self::MANAGE_PHOTO,
        'pwg.images.deleteOrphans' => self::MANAGE_PHOTO,
        'pwg.images.emptyLounge' => self::MANAGE_PHOTO,
        'pwg.images.setInfo' => self::MANAGE_PHOTO,

        'pwg.categories.add' => self::CREATE_ALBUM,
        'pwg.categories.delete' => self::MANAGE_ALBUM,
        'pwg.categories.move' => self::MANAGE_ALBUM,
        'pwg.categories.setRepresentative' => self::MANAGE_ALBUM,
        'pwg.categories.deleteRepresentative' => self::MANAGE_ALBUM,
        'pwg.categories.refreshRepresentative' => self::MANAGE_ALBUM,
        'pwg.categories.setInfo' => self::MANAGE_ALBUM,
        'pwg.categories.setRank' => self::MANAGE_ALBUM,

        'pwg.tags.add' => self::MANAGE_TAG,
        'pwg.tags.delete' => self::MANAGE_TAG,
        'pwg.tags.rename' => self::MANAGE_TAG,
        'pwg.tags.duplicate' => self::MANAGE_TAG,
        'pwg.tags.merge' => self::MANAGE_TAG,

        'pwg.users.favorites.add' => self::PRIVATE_COLLECTION,
        'pwg.users.favorites.remove' => self::PRIVATE_COLLECTION,
        'pwg.users.preferences.set' => self::ACCOUNT_PREFERENCE,
    ];

    /** @var list<string> */
    private const RESTRICTED_ROLE_COMMON_READ_METHODS = [
        'pwg.getVersion',
        'pwg.session.getStatus',
        'pwg.session.logout',
        'pwg.categories.getImages',
        'pwg.categories.getList',
        'pwg.images.getInfo',
        'pwg.images.search',
        'pwg.images.filteredSearch.create',
        'pwg.tags.getList',
        'pwg.tags.getImages',
        'pwg.history.log',
    ];

    /**
     * A true result permits the request to continue through Piwigo's own
     * authorization. It never bypasses Core, Community, album or ownership
     * checks.
     */
    public static function roleAllows(
        string $role,
        string $capability,
        bool $anonymousPresenterReady = false,
    ): bool {
        if ($role === Access::ROLE_SYSTEM_ADMIN) {
            // The independent technical account is governed by Piwigo's
            // webmaster/admin checks and the Class Archive Admin boundary,
            // not projected into a member role.
            return true;
        }

        if (in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER], true)) {
            return in_array($capability, [
                self::COMMENT_IMAGE,
                self::RATE_IMAGE,
                self::UPLOAD_PHOTO,
                self::MANAGE_PHOTO,
                self::CREATE_ALBUM,
                self::MANAGE_ALBUM,
                self::MANAGE_TAG,
                self::PRIVATE_COLLECTION,
                self::ACCOUNT_PREFERENCE,
            ], true);
        }

        if ($role === Access::ROLE_FAMILY) {
            // Family uploads must use the future audited Pending Submission
            // controller. Direct Core/Community upload is intentionally DENY.
            return in_array($capability, [self::PRIVATE_COLLECTION, self::ACCOUNT_PREFERENCE], true);
        }

        if ($role === Access::ROLE_ANONYMOUS) {
            // Until the presenter replaces every public author/user identifier
            // with a context-scoped pseudonym, even comment insertion is DENY.
            return $capability === self::COMMENT_IMAGE && $anonymousPresenterReady;
        }

        return false;
    }

    public static function requiredCapabilityForWsMethod(string $methodName): ?string
    {
        return self::WS_CAPABILITIES[$methodName] ?? null;
    }

    /**
     * Every managed member principal is fail-closed for unclassified WS
     * methods. New Core/plugin methods must be reviewed and added here before
     * activation. SYSTEM_ADMIN retains Piwigo's technical-admin surface; its
     * business mutations remain a separately visible production blocker.
     */
    public static function roleAllowsUnclassifiedWs(string $role, string $methodName): bool
    {
        if ($role === Access::ROLE_SYSTEM_ADMIN) {
            return true;
        }

        if (str_starts_with($methodName, 'reflection.')) {
            return in_array($role, [
                Access::ROLE_CLASSMATE,
                Access::ROLE_TEACHER,
                Access::ROLE_FAMILY,
                Access::ROLE_ANONYMOUS,
            ], true);
        }

        if (in_array($methodName, self::RESTRICTED_ROLE_COMMON_READ_METHODS, true)) {
            return in_array($role, [
                Access::ROLE_CLASSMATE,
                Access::ROLE_TEACHER,
                Access::ROLE_FAMILY,
                Access::ROLE_ANONYMOUS,
            ], true);
        }

        return in_array($role, [
            Access::ROLE_CLASSMATE,
            Access::ROLE_TEACHER,
            Access::ROLE_FAMILY,
        ], true) && $methodName === 'pwg.users.favorites.getList';
    }

    /**
     * @param mixed $allowed bool or an existing PwgError
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function onWsInvokeAllowed($allowed, string $methodName, array $params)
    {
        unset($params);

        if ($allowed !== true || !Access::isEnforcementEnabled() || Access::hasCoreMutationPermit()) {
            return $allowed;
        }

        $role = self::currentRole();
        $capability = self::requiredCapabilityForWsMethod($methodName);
        if (
            $role !== null
            && (
                ($capability !== null && self::roleAllows($role, $capability, self::anonymousPresenterReady()))
                || ($capability === null && self::roleAllowsUnclassifiedWs($role, $methodName))
            )
        ) {
            return $allowed;
        }

        // Login runs while the actor is still the Core guest. Access validates
        // and binds the resulting principal in finalize_login/user_login.
        if ($role === null && $methodName === 'pwg.session.login') {
            return $allowed;
        }

        return self::forbiddenResult();
    }

    /** Guard picture.php's non-WS comment/rating/favorite actions. */
    public static function guardPictureMutation(): void
    {
        if (!Access::isEnforcementEnabled()) {
            return;
        }

        $action = isset($_GET['action']) && is_scalar($_GET['action'])
            ? (string) $_GET['action']
            : '';

        $capability = null;
        if ($action === 'rate') {
            $capability = self::RATE_IMAGE;
        } elseif (in_array($action, ['add_to_favorites', 'remove_from_favorites'], true)) {
            $capability = self::PRIVATE_COLLECTION;
        } elseif (
            isset($_POST['content'])
            || in_array($action, ['edit_comment', 'delete_comment', 'validate_comment'], true)
        ) {
            $capability = self::COMMENT_IMAGE;
        }

        if ($capability !== null && !self::currentPrincipalAllows($capability)) {
            self::denyHttp();
        }
    }

    /** Guard comments.php's direct moderation/edit actions. */
    public static function guardCommentsMutation(): void
    {
        if (!Access::isEnforcementEnabled()) {
            return;
        }

        $hasMutation = isset($_POST['content']);
        foreach (['delete', 'validate', 'edit'] as $action) {
            if (array_key_exists($action, $_GET)) {
                $hasMutation = true;
                break;
            }
        }

        if ($hasMutation && !self::currentPrincipalAllows(self::COMMENT_IMAGE)) {
            self::denyHttp();
        }
    }

    /**
     * Guard Community's direct HTML uploader, which executes at loc_end_index
     * and does not necessarily call a WS method for the legacy browser form.
     */
    public static function guardCommunityRoute(): void
    {
        if (!Access::isEnforcementEnabled()) {
            return;
        }

        global $page, $tokens;

        $section = is_array($page ?? null) && isset($page['section']) && is_scalar($page['section'])
            ? (string) $page['section']
            : '';
        $firstToken = is_array($tokens ?? null) && isset($tokens[0]) && is_scalar($tokens[0])
            ? (string) $tokens[0]
            : '';

        if (
            in_array($section, ['add_photos', 'edit_photos'], true)
            || in_array($firstToken, ['add_photos', 'edit_photos'], true)
        ) {
            if (!self::currentPrincipalAllows(self::UPLOAD_PHOTO)) {
                self::denyHttp();
            }
        }
    }

    private static function currentPrincipalAllows(string $capability): bool
    {
        $role = self::currentRole();
        return $role !== null
            && self::roleAllows($role, $capability, self::anonymousPresenterReady());
    }

    private static function currentRole(): ?string
    {
        global $user;

        $userId = is_array($user ?? null) ? (int) ($user['id'] ?? 0) : 0;
        if ($userId <= 0) {
            return null;
        }

        try {
            $context = Access::resolveAuthorizationContext($userId);
            $role = is_array($context) ? ($context['role'] ?? null) : null;
            return is_string($role) && $role !== '' ? $role : null;
        } catch (Throwable) {
            // Missing principal state, schema drift and database errors are
            // UNKNOWN and therefore DENY.
            return null;
        }
    }

    private static function anonymousPresenterReady(): bool
    {
        global $conf;

        // Piwigo 16.4 config.param is VARCHAR(40); keep this durable gate key
        // below that boundary instead of relying on a silently truncated name.
        $configured = $conf['class_identity_anon_presenter_ready'] ?? false;
        if (!in_array($configured, [true, 1, '1'], true)) {
            return false;
        }

        // A separate presenter/renderer must positively attest readiness.
        // Merely flipping a config value cannot expose global Piwigo author
        // names or ids in HTML/API hydration data.
        try {
            return function_exists('trigger_change')
                && trigger_change('class_identity_anonymous_presenter_ready', false) === true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return mixed */
    private static function forbiddenResult()
    {
        if (class_exists('PwgError')) {
            return new \PwgError(403, 'Access denied');
        }

        return false;
    }

    private static function denyHttp(): never
    {
        if (!headers_sent()) {
            http_response_code(403);
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header('Content-Type: text/plain; charset=utf-8');
        }

        echo 'Access denied.';
        exit;
    }
}
