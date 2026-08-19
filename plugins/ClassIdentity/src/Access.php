<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use Throwable;

/**
 * The single runtime authorization boundary for ClassIdentity principals.
 *
 * Piwigo users and groups remain the authentication/ACL projection, but they
 * are never the source of a Class Archive role once enforcement is enabled.
 */
final class Access
{
    private const BOOTSTRAP_CONTEXT_VALUE = 'class-archive-cli-bootstrap-v1';
    private const MAINTENANCE_ROOT = '/var/www/html/piwigo';
    private const MAINTENANCE_MARKER = self::MAINTENANCE_ROOT . '/_data/.class-archive-maintenance';
    private const MAINTENANCE_CONTENT = "class-archive-identity-bootstrap\n";

    public const PRINCIPAL_SEAT_ACCOUNT = 'SEAT_ACCOUNT';
    public const PRINCIPAL_SYSTEM_ACCOUNT = 'SYSTEM_ACCOUNT';

    public const ROLE_CLASSMATE = 'CLASSMATE';
    public const ROLE_TEACHER = 'TEACHER';
    public const ROLE_FAMILY = 'FAMILY';
    public const ROLE_ANONYMOUS = 'ANONYMOUS';
    public const ROLE_SYSTEM_ADMIN = 'SYSTEM_ADMIN';

    private const SESSION_PRINCIPAL_ID = 'class_identity_principal_id';
    private const SESSION_PRINCIPAL_EPOCH = 'class_identity_principal_auth_epoch';
    private const SESSION_ISSUED_AT = 'class_identity_issued_at';

    private static int $provisioningPermitDepth = 0;
    private static int $coreMutationPermitDepth = 0;
    private static bool $terminatingRequest = false;
    private static ?Repository $repository = null;

    public static function isEnforcementEnabled(): bool
    {
        global $conf;

        // Once the plugin is active, an absent/corrupt setting must not turn
        // authorization off. Even an explicit false value is ignored by every
        // HTTP/FPM request and by arbitrary CLI entry points. Only the trusted
        // bootstrap process with the exact durable marker may open the window.
        $value = $conf['class_identity_enforcement'] ?? true;
        $explicitlyDisabled = in_array($value, [false, 0, '0', 'false'], true);
        return !$explicitlyDisabled || !self::hasTrustedBootstrapContext();
    }

    /**
     * A deliberately explicit bootstrap window. This setting must be false
     * only while migrations and the first SYSTEM_ADMIN principal are being
     * provisioned. Admin Console authorization never uses this bypass.
     */
    public static function enforcementDisabledForBootstrap(): bool
    {
        global $conf;

        return in_array($conf['class_identity_enforcement'] ?? true, [false, 0, '0', 'false'], true)
            && self::hasTrustedBootstrapContext();
    }

    /**
     * An explicit false value outside the one trusted CLI bootstrap context is
     * configuration drift, not a feature flag. HTTP/FPM must block the entire
     * request instead of silently operating in a reduced or legacy ACL mode.
     */
    public static function hasUntrustedDisabledConfiguration(): bool
    {
        global $conf;

        return in_array($conf['class_identity_enforcement'] ?? true, [false, 0, '0', 'false'], true)
            && !self::hasTrustedBootstrapContext();
    }

    private static function hasTrustedBootstrapContext(): bool
    {
        if (
            PHP_SAPI !== 'cli'
            || !defined('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT')
            || constant('CLASS_IDENTITY_TRUSTED_BOOTSTRAP_CONTEXT') !== self::BOOTSTRAP_CONTEXT_VALUE
            || !function_exists('posix_geteuid')
            || !function_exists('posix_getpwuid')
        ) {
            return false;
        }
        $uid = posix_geteuid();
        $account = posix_getpwuid($uid);
        if ($uid === 0 || !is_array($account) || ($account['name'] ?? null) !== 'nginx') {
            return false;
        }

        $root = realpath(self::MAINTENANCE_ROOT);
        $dataDirectory = realpath(self::MAINTENANCE_ROOT . '/_data');
        if (
            $root !== self::MAINTENANCE_ROOT
            || $dataDirectory !== self::MAINTENANCE_ROOT . '/_data'
            || !is_dir($dataDirectory)
            || is_link(self::MAINTENANCE_ROOT)
            || is_link(self::MAINTENANCE_ROOT . '/_data')
        ) {
            return false;
        }

        clearstatcache(true, self::MAINTENANCE_MARKER);
        $metadata = @lstat(self::MAINTENANCE_MARKER);
        if (
            !is_array($metadata)
            || is_link(self::MAINTENANCE_MARKER)
            || (($metadata['mode'] ?? 0) & 0170000) !== 0100000
            || realpath(self::MAINTENANCE_MARKER) !== self::MAINTENANCE_MARKER
            || (int) ($metadata['uid'] ?? -1) !== $uid
            || (($metadata['mode'] ?? 0) & 0777) !== 0600
            || (int) ($metadata['nlink'] ?? 0) !== 1
        ) {
            return false;
        }
        $contents = @file_get_contents(self::MAINTENANCE_MARKER);
        return is_string($contents) && hash_equals(self::MAINTENANCE_CONTENT, $contents);
    }

    /**
     * Disable and expire Piwigo's long-lived remember cookie before Core's
     * auto_login() can consume it. Server-side session/key revocation alone
     * cannot revoke this self-contained cookie.
     */
    public static function disableRememberMeRuntime(): void
    {
        global $conf;

        if (!self::isEnforcementEnabled()) {
            return;
        }

        $conf['authorize_remembering'] = false;
        $cookieName = $conf['remember_me_name'] ?? null;
        if (!is_string($cookieName) || $cookieName === '' || !isset($_COOKIE[$cookieName])) {
            return;
        }

        unset($_COOKIE[$cookieName]);
        if (headers_sent()) {
            return;
        }

        $path = function_exists('cookie_path') ? (string) \cookie_path() : '/';
        $options = [
            'expires' => 1,
            'path' => $path === '' ? '/' : $path,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        $domain = (string) ini_get('session.cookie_domain');
        if ($domain !== '') {
            $options['domain'] = $domain;
        }
        setcookie($cookieName, '', $options);
    }

    /** @template T
     *  @param callable(): T $callback
     *  @return T
     */
    public static function withProvisioningPermit(callable $callback)
    {
        ++self::$provisioningPermitDepth;
        try {
            return $callback();
        } finally {
            --self::$provisioningPermitDepth;
        }
    }

    /** @template T
     *  @param callable(): T $callback
     *  @return T
     */
    public static function withCoreMutationPermit(callable $callback)
    {
        ++self::$coreMutationPermitDepth;
        try {
            return $callback();
        } finally {
            --self::$coreMutationPermitDepth;
        }
    }

    public static function hasProvisioningPermit(): bool
    {
        return self::$provisioningPermitDepth > 0;
    }

    public static function hasCoreMutationPermit(): bool
    {
        return self::$coreMutationPermitDepth > 0;
    }

    /** @param list<string> $errors
     *  @param array<string, mixed> $candidate
     *  @return list<string>
     */
    public static function onRegisterUserCheck(array $errors, array $candidate): array
    {
        unset($candidate); // Never retain or inspect the plaintext password payload.

        if (self::isEnforcementEnabled() && !self::hasProvisioningPermit()) {
            $errors[] = 'Account provisioning is not available through this route.';
        }

        return $errors;
    }

    /** @param array<string, mixed> $state
     *  @param array<string, mixed> $coreUser
     *  @return array<string, mixed>
     */
    public static function onFinalizeLogin(array $state, array $coreUser, bool $rememberMe): array
    {
        if (!self::isEnforcementEnabled() || ($state['can_login'] ?? false) !== true) {
            return $state;
        }

        // Remember Me is deliberately disabled for V1 because Core's cookie is
        // not independently revocable. Reject a forged/direct remember request.
        if ($rememberMe) {
            $state['can_login'] = false;
            $state['reason'] = 'class_identity_access_denied';
            return $state;
        }

        $userId = (int) ($coreUser['id'] ?? 0);
        if (self::resolveAuthorizationContext($userId) === null) {
            $state['can_login'] = false;
            $state['reason'] = 'class_identity_access_denied';
        }

        return $state;
    }

    public static function onUserLogin(int $userId): void
    {
        if (!self::isEnforcementEnabled()) {
            return;
        }

        $context = self::resolveAuthorizationContext($userId);
        if ($context === null) {
            self::clearSessionAuthorization();
            return;
        }

        self::writeSessionAuthorization($context);
    }

    /** @param array<string, mixed> $coreUser */
    public static function onUserInit(array $coreUser): void
    {
        if (self::hasUntrustedDisabledConfiguration()) {
            self::denyCurrentRequest(503);
        }
        if (!self::isEnforcementEnabled()) {
            return;
        }

        $userId = (int) ($coreUser['id'] ?? 0);
        if (self::isGuest($coreUser, $userId)) {
            // Public login/Claim/Invite surfaces remain reachable. Their own
            // mutation endpoints still enforce CSRF, token and throttling.
            self::clearSessionAuthorization();
            return;
        }

        $context = self::resolveAuthorizationContext($userId);
        if ($context === null) {
            self::denyCurrentRequest();
        }

        // Header API keys are deliberately stateless and bypass user_login.
        // Their current principal is therefore checked directly on every call.
        if (defined('PWG_API_KEY_REQUEST')) {
            return;
        }

        if (!self::sessionAuthorizationMatches($context)) {
            self::denyCurrentRequest();
        }
    }

    /** @param mixed $userId */
    public static function onUserLogout($userId): void
    {
        unset($userId);
        self::clearSessionAuthorization();
    }

    /**
     * @param mixed $allowed bool or an existing PwgError
     * @param array<string, mixed> $params
     * @return mixed
     */
    public static function onWsInvokeAllowed($allowed, string $methodName, array $params)
    {
        if ($allowed !== true || !self::isEnforcementEnabled() || self::hasCoreMutationPermit()) {
            return $allowed;
        }

        if (!self::isSensitiveCoreMethod($methodName, $params)) {
            return $allowed;
        }

        if (class_exists('PwgError')) {
            return new \PwgError(403, 'Access denied');
        }

        return false;
    }

    public static function guardClassIdentityAdminRoute(): void
    {
        $page = isset($_GET['page']) && is_scalar($_GET['page']) ? (string) $_GET['page'] : '';
        $section = isset($_GET['section']) && is_scalar($_GET['section']) ? (string) $_GET['section'] : '';

        // Piwigo's native user/profile/group HTML controllers write Core
        // identity state directly. In particular admin/profile.php includes
        // profile.php after PHPWG_ROOT_PATH is defined, so the public
        // loc_begin_profile hook is never emitted. These complete business
        // routes must never reach Core's direct
        // mutation controllers. A SYSTEM_ADMIN clicking a legacy menu item
        // is sent to the audited Class Archive console; non-GET requests and
        // every non-admin principal remain fail-closed with 403. Technical
        // Core maintenance pages remain available.
        $managedIdentityPages = [
            'profile' => 'identities',
            'user_list' => 'identities',
            'group_list' => 'system',
            'group_perm' => 'system',
            'user_perm' => 'identities',
        ];
        $businessTab = $managedIdentityPages[$page] ?? null;
        if (
            self::isEnforcementEnabled()
            && $businessTab !== null
            && !self::hasCoreMutationPermit()
        ) {
            $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
                ? strtoupper($_SERVER['REQUEST_METHOD'])
                : 'GET';
            if (in_array($method, ['GET', 'HEAD'], true) && self::isActiveSystemAdmin()) {
                self::redirectNativeBusinessRoute($businessTab);
            }
            self::denyCurrentRequest(403);
        }

        $isAliasRoute = str_starts_with($page, 'plugin-ClassIdentity-');
        $isDirectPluginRoute = $page === 'plugin' && $section === 'ClassIdentity/admin.php';
        if (!$isAliasRoute && !$isDirectPluginRoute) {
            return;
        }

        if (!self::isActiveSystemAdmin()) {
            self::denyCurrentRequest(403);
        }
    }

    public static function guardCorePasswordRoute(): void
    {
        if (self::isEnforcementEnabled()) {
            self::denyCurrentRequest(403);
        }
    }

    public static function guardCoreProfileMutation(): void
    {
        if (!self::isEnforcementEnabled() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            return;
        }

        foreach (['use_new_pwd', 'password', 'passwordConf', 'new_password', 'conf_new_password'] as $field) {
            if (isset($_POST[$field]) && is_scalar($_POST[$field]) && (string) $_POST[$field] !== '') {
                self::denyCurrentRequest(403);
            }
        }
    }

    /** @param list<array<string, string>> $links
     *  @return list<array<string, string>>
     */
    public static function addAdminMenuLink(array $links): array
    {
        if (self::isActiveSystemAdmin()) {
            $links[] = [
                'NAME' => '班级档案馆管理控制台',
                'URL' => 'admin.php?page=plugin-ClassIdentity-dashboard',
            ];
        }

        return $links;
    }

    public static function isActiveSystemAdmin(?int $userId = null): bool
    {
        global $user;

        $resolvedUserId = $userId ?? (int) ($user['id'] ?? 0);
        if ($resolvedUserId <= 0) {
            return false;
        }

        $context = self::resolveAuthorizationContext($resolvedUserId);
        return $context !== null
            && ($context['role'] ?? null) === self::ROLE_SYSTEM_ADMIN;
    }

    /**
     * MediaGuard's explicit-principal bridge. Null always means DENY.
     */
    public static function resolveMediaRole(int $userId): ?string
    {
        $context = self::resolveAuthorizationContext($userId);
        $role = $context['role'] ?? null;

        return is_string($role) ? $role : null;
    }

    /** @return array<string, mixed>|null */
    public static function resolveAuthorizationContext(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $context = self::repository()->findAuthorizationContextByPiwigoUserId($userId);
            if (!is_array($context) || (int) ($context['piwigo_user_id'] ?? 0) !== $userId) {
                return null;
            }

            $principalType = (string) ($context['principal_type'] ?? '');
            if (($context['principal_state'] ?? null) !== 'ACTIVE') {
                return null;
            }

            $coreStatus = CoreAdapter::coreStatus($userId);
            if ($principalType === self::PRINCIPAL_SYSTEM_ACCOUNT) {
                if (!in_array($coreStatus, ['admin', 'webmaster'], true)) {
                    return null;
                }
                if (
                    ($context['system_role'] ?? null) !== self::ROLE_SYSTEM_ADMIN
                    || ($context['account_id'] ?? null) !== null
                    || ($context['seat_id'] ?? null) !== null
                    || ($context['identity_id'] ?? null) !== null
                ) {
                    return null;
                }
                $context['role'] = self::ROLE_SYSTEM_ADMIN;
                return $context;
            }

            if ($principalType !== self::PRINCIPAL_SEAT_ACCOUNT || $coreStatus !== 'normal') {
                return null;
            }

            if (!self::activeSeatContextIsConsistent($context, $userId)) {
                return null;
            }

            $role = (string) $context['seat_type'];
            if (!CoreAdapter::managedGroupProjectionMatches($userId, $role, $context)) {
                return null;
            }

            $context['role'] = $role;
            return $context;
        } catch (Throwable) {
            // UNKNOWN is never ALLOW, including migration drift or DB outage.
            return null;
        }
    }

    public static function resetRepositoryForTests(): void
    {
        self::$repository = null;
    }

    /** @param array<string, mixed> $context */
    private static function activeSeatContextIsConsistent(array $context, int $userId): bool
    {
        foreach (['account_id', 'seat_id', 'identity_id'] as $requiredId) {
            if ((int) ($context[$requiredId] ?? 0) <= 0) {
                return false;
            }
        }

        if (
            ($context['account_state'] ?? null) !== 'ACTIVE'
            || (int) ($context['current_marker'] ?? 0) !== 1
            || ($context['seat_state'] ?? null) !== 'ACTIVE'
            || ($context['identity_state'] ?? null) !== 'ACTIVE'
            || ($context['role_group_state'] ?? null) !== 'ACTIVE'
        ) {
            return false;
        }

        $seatType = (string) ($context['seat_type'] ?? '');
        $managedRole = (string) ($context['managed_role_code'] ?? '');
        if ($seatType === '' || $seatType !== $managedRole) {
            return false;
        }

        $identityType = (string) ($context['identity_type'] ?? '');
        $validRoleForIdentity = match ($identityType) {
            'CLASSMATE' => in_array($seatType, [
                self::ROLE_CLASSMATE,
                self::ROLE_FAMILY,
                self::ROLE_ANONYMOUS,
            ], true),
            'TEACHER' => $seatType === self::ROLE_TEACHER,
            default => false,
        };

        return $validRoleForIdentity
            && (int) ($context['expected_group_id'] ?? 0) > 0
            && (string) ($context['expected_group_name'] ?? '') !== '';
    }

    /** @param array<string, mixed> $context */
    private static function writeSessionAuthorization(array $context): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }

        $_SESSION[self::SESSION_PRINCIPAL_ID] = (int) $context['principal_id'];
        $_SESSION[self::SESSION_PRINCIPAL_EPOCH] = (int) ($context['principal_auth_epoch'] ?? 0);
        $_SESSION[self::SESSION_ISSUED_AT] = time();
    }

    /** @param array<string, mixed> $context */
    private static function sessionAuthorizationMatches(array $context): bool
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return false;
        }

        return isset(
            $_SESSION[self::SESSION_PRINCIPAL_ID],
            $_SESSION[self::SESSION_PRINCIPAL_EPOCH],
            $_SESSION[self::SESSION_ISSUED_AT]
        )
            && (int) $_SESSION[self::SESSION_PRINCIPAL_ID] === (int) $context['principal_id']
            && (int) $_SESSION[self::SESSION_PRINCIPAL_EPOCH] === (int) ($context['principal_auth_epoch'] ?? 0);
    }

    private static function clearSessionAuthorization(): void
    {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            return;
        }

        unset(
            $_SESSION[self::SESSION_PRINCIPAL_ID],
            $_SESSION[self::SESSION_PRINCIPAL_EPOCH],
            $_SESSION[self::SESSION_ISSUED_AT],
        );
    }

    /** @param array<string, mixed> $coreUser */
    private static function isGuest(array $coreUser, int $userId): bool
    {
        global $conf;

        return $userId <= 0
            || $userId === (int) ($conf['guest_id'] ?? 0)
            || ($coreUser['status'] ?? null) === 'guest';
    }

    /** @param array<string, mixed> $params */
    private static function isSensitiveCoreMethod(string $methodName, array $params): bool
    {
        if (in_array($methodName, ['pwg.users.add', 'pwg.users.setMainUser'], true)) {
            return true;
        }

        if ($methodName === 'pwg.users.api_key.create') {
            // V1 does not issue unmanaged stateless credentials. A future
            // audited ClassIdentity service may use the scoped permit.
            return true;
        }

        if (in_array($methodName, [
            'pwg.users.delete',
            'pwg.users.setInfo',
            'pwg.users.setMyInfo',
            'pwg.users.getAuthKey',
            'pwg.users.generatePasswordLink',
        ], true) || str_starts_with($methodName, 'pwg.users.api_key.')) {
            return self::requestTouchesManagedUser($params);
        }

        if (in_array($methodName, ['pwg.groups.addUser', 'pwg.groups.deleteUser'], true)) {
            return self::requestTouchesManagedUser($params)
                || self::requestTouchesManagedGroup($params);
        }

        if (in_array($methodName, [
            'pwg.groups.delete',
            'pwg.groups.setInfo',
            'pwg.groups.merge',
            'pwg.groups.duplicate',
        ], true)) {
            return self::requestTouchesManagedGroup($params)
                || (isset($params['name']) && is_scalar($params['name'])
                    && CoreAdapter::isManagedGroupName((string) $params['name']))
                || (isset($params['copy_name']) && is_scalar($params['copy_name'])
                    && CoreAdapter::isManagedGroupName((string) $params['copy_name']));
        }

        if ($methodName === 'pwg.groups.add') {
            return !isset($params['name'])
                || !is_scalar($params['name'])
                || CoreAdapter::isManagedGroupName((string) $params['name']);
        }

        return false;
    }

    /** @param array<string, mixed> $params */
    private static function requestTouchesManagedGroup(array $params): bool
    {
        $values = [];
        foreach (['group_id', 'destination_group_id', 'merge_group_id'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $candidate = is_array($params[$key]) ? $params[$key] : [$params[$key]];
            $values = array_merge($values, $candidate);
        }

        foreach ($values as $value) {
            if (!is_scalar($value) || (int) $value <= 0) {
                return true;
            }
            if (CoreAdapter::isManagedGroupId((int) $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $params */
    private static function requestTouchesManagedUser(array $params): bool
    {
        global $user;

        $ids = $params['user_id'] ?? [(int) ($user['id'] ?? 0)];
        $ids = is_array($ids) ? $ids : [$ids];
        foreach ($ids as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                try {
                    if (self::repository()->findPrincipalByPiwigoUserId((int) $id) !== null) {
                        return true;
                    }
                } catch (Throwable) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function repository(): Repository
    {
        if (self::$repository === null) {
            self::$repository = Repository::fromPiwigo();
        }

        return self::$repository;
    }

    private static function denyCurrentRequest(int $status = 403): never
    {
        if (!self::$terminatingRequest) {
            self::$terminatingRequest = true;
            self::clearSessionAuthorization();
            try {
                if (isset($_SESSION['pwg_uid']) && function_exists('logout_user')) {
                    \logout_user();
                }
            } catch (Throwable) {
                // Authorization failure remains fail closed even if cleanup or
                // its activity log cannot reach the database.
                if (isset($_SESSION) && is_array($_SESSION)) {
                    $_SESSION = [];
                }
            }
        }

        if (!headers_sent()) {
            http_response_code($status);
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
            header(defined('IN_WS') ? 'Content-Type: application/json; charset=utf-8' : 'Content-Type: text/plain; charset=utf-8');
        }

        echo defined('IN_WS')
            ? '{"stat":"fail","err":403,"message":"Access denied"}'
            : 'Access denied.';
        exit;
    }

    private static function redirectNativeBusinessRoute(string $tab): never
    {
        if (!in_array($tab, ['identities', 'system'], true)) {
            self::denyCurrentRequest(403);
        }

        if (!headers_sent()) {
            header(
                'Location: ' . get_root_url() . 'admin.php?page=plugin-ClassIdentity-' . rawurlencode($tab),
                true,
                303
            );
            header('Cache-Control: no-store, private');
            header('Referrer-Policy: no-referrer');
        }

        exit;
    }
}
