<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Access;
use ClassIdentity\ProvisioningService;
use ClassIdentity\RateLimiter;
use ClassIdentity\Repository;

require_once CLASS_IDENTITY_PATH . 'src/Repository.php';
require_once CLASS_IDENTITY_PATH . 'src/RateLimiter.php';
require_once CLASS_IDENTITY_PATH . 'src/Audit.php';
require_once CLASS_IDENTITY_PATH . 'src/CoreAdapter.php';
require_once CLASS_IDENTITY_PATH . 'src/Access.php';
require_once CLASS_IDENTITY_PATH . 'src/ProvisioningService.php';
require_once CLASS_IDENTITY_PATH . 'src/SubmissionService.php';
require_once CLASS_IDENTITY_PATH . 'src/Http.php';

/**
 * Public ClassIdentity boundary.
 *
 * The controller is split across Piwigo's section/begin/end index hooks so a
 * mutation is authorized and completed before page headers are emitted while
 * the result can still be rendered inside the normal gallery shell.
 */
final class ClassIdentityPublicController
{
    private const ROUTE_CLAIM = 'claim';
    private const ROUTE_FAMILY_INVITE = 'family-invite';
    private const ROUTE_MY_IDENTITY = 'my';

    /** @var array<string, mixed> */
    private static array $view = [];

    public static function onSectionInit(): void
    {
        global $conf, $page, $tokens, $user;

        if (!is_array($tokens) || ($tokens[0] ?? null) !== 'class-identity') {
            return;
        }

        $route = isset($tokens[1]) && is_string($tokens[1]) && $tokens[1] !== ''
            ? $tokens[1]
            : self::ROUTE_CLAIM;
        $unexpectedTokens = array_filter(
            array_slice($tokens, 2),
            static fn ($token): bool => is_scalar($token) && (string) $token !== '',
        );
        if ($unexpectedTokens !== []) {
            $route = 'not-found';
        }

        $titles = [
            self::ROUTE_CLAIM => '认领班级身份',
            self::ROUTE_FAMILY_INVITE => '接受家庭席位邀请',
            self::ROUTE_MY_IDENTITY => '我的身份',
        ];

        $page['class_identity_public_route'] = $route;
        $page['section'] = 'class_identity_public';
        $page['section_title'] = $titles[$route] ?? '页面不存在';
        $page['title'] = $page['section_title'];
        $page['is_homepage'] = false;
        $page['is_external'] = true;
        $page['items'] = [];
        $page['meta_robots']['noindex'] = 1;
        $page['body_id'] = 'classIdentityPublicPage';
        $page['body_classes'][] = 'section-class-identity';
        $page['body_data']['section'] = 'class-identity';

        // The private-gallery baseline intentionally maps Piwigo's reserved
        // guest to access level 0, so index.php's later ACCESS_GUEST shell
        // check would redirect before this controller can render Claim/Invite.
        // Lift only the shell status for these two exact routes. The reserved
        // guest id remains unchanged, page items stay empty, Access::isGuest()
        // still recognizes it, and MediaGuard continues to deny every byte.
        $userId = (int) ($user['id'] ?? 0);
        if (
            in_array($route, [self::ROUTE_CLAIM, self::ROUTE_FAMILY_INVITE], true)
            && ($userId <= 0 || $userId === (int) ($conf['guest_id'] ?? 0))
        ) {
            $user['status'] = 'generic';
            $page['class_identity_guest_shell_only'] = true;
        }
    }

    public static function onBeginIndex(): void
    {
        global $page;

        $route = $page['class_identity_public_route'] ?? null;
        if (!is_string($route)) {
            return;
        }

        ClassIdentityHttp::noStore();
        self::rejectSecretsInUrl();

        if (!in_array($route, [self::ROUTE_CLAIM, self::ROUTE_FAMILY_INVITE, self::ROUTE_MY_IDENTITY], true)) {
            ClassIdentityHttp::abort(404, '页面不存在');
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'POST'], true)) {
            header('Allow: GET, POST');
            ClassIdentityHttp::abort(405, 'Method Not Allowed');
        }

        $isGuest = self::isGuest();
        if (($route === self::ROUTE_CLAIM || $route === self::ROUTE_FAMILY_INVITE) && !$isGuest) {
            ClassIdentityHttp::abort(403, '请先退出当前账号，再使用认领或邀请入口。');
        }
        if ($route === self::ROUTE_MY_IDENTITY && $isGuest) {
            ClassIdentityHttp::abort(403, '请先登录。');
        }

        self::$view = [
            'CA_PUBLIC_ROUTE' => $route,
            'CA_ERROR' => null,
            'CA_SUCCESS' => null,
        ];

        if ($method === 'POST') {
            // Piwigo CSRF is mandatory; Origin is additionally mandatory for
            // these credential-bearing forms. Neither Referer nor JS is used
            // as an authorization signal.
            ClassIdentityHttp::requireMutation();
            self::requireExactOrigin();

            $action = self::postPlain('action', 64);
            unset($_POST['action'], $_POST['pwg_token']);

            if ($route === self::ROUTE_CLAIM && $action === 'claim') {
                self::handleFormalClaim();
            } elseif ($route === self::ROUTE_FAMILY_INVITE && $action === 'accept_family') {
                self::handleFamilyAcceptance();
            } elseif ($route === self::ROUTE_MY_IDENTITY && $action === 'issue_family_invitation') {
                self::handleFamilyInvitationIssue();
            } elseif ($route === self::ROUTE_MY_IDENTITY && $action === 'activate_anonymous') {
                self::handleAnonymousActivation();
            } elseif ($route === self::ROUTE_MY_IDENTITY && $action === 'submit_family_photo') {
                self::handleFamilySubmission();
            } else {
                self::clearSensitivePostedFields([
                    'claim_code',
                    'invitation_code',
                    'password',
                    'password_confirmation',
                ]);
                ClassIdentityHttp::abort(400, '请求无效');
            }
        }

        if ($route === self::ROUTE_MY_IDENTITY) {
            try {
                self::$view['CA_MY'] = self::loadMyIdentity();
            } catch (Throwable $error) {
                self::logFailure('my_identity_read', $error);
                ClassIdentityHttp::abort(503, '身份状态暂时无法确认，已按默认拒绝处理。');
            }
        }
    }

    public static function onEndIndex(): void
    {
        global $page, $template;

        $route = $page['class_identity_public_route'] ?? null;
        if (!is_string($route) || !in_array($route, [
            self::ROUTE_CLAIM,
            self::ROUTE_FAMILY_INVITE,
            self::ROUTE_MY_IDENTITY,
        ], true)) {
            return;
        }

        $templates = [
            self::ROUTE_CLAIM => 'claim.tpl',
            self::ROUTE_FAMILY_INVITE => 'family-invite.tpl',
            self::ROUTE_MY_IDENTITY => 'my-identity.tpl',
        ];
        $baseUrl = get_root_url() . 'index.php?/class-identity/';
        $headerTemplate = realpath(CLASS_IDENTITY_PATH . 'template/public/_header.tpl');
        if ($headerTemplate === false) {
            ClassIdentityHttp::abort(503, 'Public identity template unavailable');
        }
        $template->assign(array_merge(self::$view, [
            'CA_PUBLIC_HEADER_TEMPLATE' => 'file:' . $headerTemplate,
            'CA_CLAIM_URL' => $baseUrl . self::ROUTE_CLAIM,
            'CA_FAMILY_INVITE_URL' => $baseUrl . self::ROUTE_FAMILY_INVITE,
            'CA_MY_IDENTITY_URL' => $baseUrl . self::ROUTE_MY_IDENTITY,
            'CA_LOGIN_URL' => get_root_url() . 'identification.php',
            'CA_GALLERY_URL' => get_gallery_home_url(),
            'CA_PWG_TOKEN' => get_pwg_token(),
        ]));
        $template->set_filename(
            'class_identity_public',
            CLASS_IDENTITY_PATH . 'template/public/' . $templates[$route],
        );
        $template->assign_var_from_handle('PLUGIN_INDEX_CONTENT_BEGIN', 'class_identity_public');
    }

    private static function handleFormalClaim(): void
    {
        $claimCode = '';
        $password = '';
        $confirmation = '';

        try {
            if (!self::consumePublicAttempt('CLAIM', 'claim_code', 'roster_code')) {
                return;
            }
            $claimCode = self::pullSensitive('claim_code', 128, true);
            $password = self::pullSensitive('password', 1024, false);
            $confirmation = self::pullSensitive('password_confirmation', 1024, false);
            $rosterCode = self::postPlain('roster_code', 64);
            $username = self::postPlain('username', 64);
            $email = self::postPlain('email', 255);
            self::clearPostedFields(['roster_code', 'username', 'email']);

            if ($confirmation === '' || !hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('claim_invalid');
            }

            ProvisioningService::fromPiwigo()->claimFormal(
                $rosterCode,
                $claimCode,
                $username,
                $email,
                $password,
            );
            self::$view['CA_SUCCESS'] = '账号已创建。请使用刚刚设置的独立账号登录。';
        } catch (InvalidArgumentException $error) {
            unset($error);
            self::$view['CA_ERROR'] = '认领信息或账号资料无效，未创建账号。';
        } catch (Throwable $error) {
            self::logFailure('formal_claim', $error);
            http_response_code(503);
            self::$view['CA_ERROR'] = '认领暂时无法完成。系统已按默认拒绝处理，请稍后重试或联系管理员。';
        } finally {
            self::clearSensitivePostedFields(['claim_code', 'password', 'password_confirmation']);
            self::wipe($claimCode);
            self::wipe($password);
            self::wipe($confirmation);
        }
    }

    private static function handleFamilyAcceptance(): void
    {
        $invitationCode = '';
        $password = '';
        $confirmation = '';

        try {
            if (!self::consumePublicAttempt('FAMILY_INVITE', 'invitation_code')) {
                return;
            }
            $invitationCode = self::pullSensitive('invitation_code', 128, true);
            $password = self::pullSensitive('password', 1024, false);
            $confirmation = self::pullSensitive('password_confirmation', 1024, false);
            $username = self::postPlain('username', 64);
            $email = self::postPlain('email', 255);
            $realName = self::postPlain('real_name', 190);
            $relationship = self::postPlain('relationship', 24);
            self::clearPostedFields(['username', 'email', 'real_name', 'relationship']);

            if ($confirmation === '' || !hash_equals($password, $confirmation)) {
                throw new InvalidArgumentException('claim_invalid');
            }

            ProvisioningService::fromPiwigo()->acceptFamilyInvitation(
                $invitationCode,
                $username,
                $email,
                $password,
                $realName,
                $relationship,
            );
            self::$view['CA_SUCCESS'] = '家庭账号已创建。请使用刚刚设置的独立账号登录。';
        } catch (InvalidArgumentException $error) {
            unset($error);
            self::$view['CA_ERROR'] = '邀请信息或账号资料无效，未创建账号。';
        } catch (Throwable $error) {
            self::logFailure('family_acceptance', $error);
            http_response_code(503);
            self::$view['CA_ERROR'] = '邀请暂时无法完成。系统已按默认拒绝处理，请稍后重试或联系管理员。';
        } finally {
            self::clearSensitivePostedFields(['invitation_code', 'password', 'password_confirmation']);
            self::wipe($invitationCode);
            self::wipe($password);
            self::wipe($confirmation);
        }
    }

    private static function handleFamilyInvitationIssue(): void
    {
        try {
            $issued = ProvisioningService::fromPiwigo()->issueFamilyInvitation(self::currentUserId());
            ClassIdentityHttp::renderIssuedFamilyInvitation(
                $issued,
                get_root_url() . 'index.php?/class-identity/my',
            );
        } catch (Throwable $error) {
            self::logFailure('family_invitation_issue', $error);
            http_response_code(503);
            self::$view['CA_ERROR'] = '暂时无法生成邀请。系统已按默认拒绝处理。';
        }
    }

    private static function handleAnonymousActivation(): void
    {
        try {
            $credentials = ProvisioningService::fromPiwigo()->activateAnonymousSeat(self::currentUserId());
            ClassIdentityHttp::renderActivatedAnonymous(
                $credentials,
                get_root_url() . 'index.php?/class-identity/my',
            );
        } catch (Throwable $error) {
            self::logFailure('anonymous_activation', $error);
            http_response_code(503);
            self::$view['CA_ERROR'] = '暂时无法激活匿名席位。系统已按默认拒绝处理。';
        }
    }

    private static function handleFamilySubmission(): void
    {
        try {
            $date = self::postOptional('suggested_date', 32);
            $precision = self::postOptional('date_precision', 16) ?? 'UNKNOWN';
            $album = self::postOptional('suggested_album', 190);
            $description = self::postOptional('description', 2000);
            $file = $_FILES['submission_file'] ?? null;
            unset($_POST['suggested_date'], $_POST['date_precision'], $_POST['suggested_album'], $_POST['description'], $_FILES['submission_file']);
            if (!is_array($file)) {
                throw new InvalidArgumentException('family_submission_upload_invalid');
            }
            $id = ClassIdentitySubmissionService::fromPiwigo()->submit(
                self::currentUserId(),
                $file,
                $date,
                $precision,
                $album,
                $description,
            );
            self::$view['CA_SUCCESS'] = '照片已提交，正在等待管理员审核（编号 #' . $id . '）。';
        } catch (InvalidArgumentException $error) {
            unset($error);
            self::$view['CA_ERROR'] = '投稿资料或照片格式不符合要求，照片尚未提交。';
        } catch (Throwable $error) {
            self::logFailure('family_submission', $error);
            http_response_code(503);
            self::$view['CA_ERROR'] = '投稿暂时无法完成。系统已按默认拒绝处理，请稍后重试。';
        } finally {
            self::clearPostedFields(['suggested_date', 'date_precision', 'suggested_album', 'description']);
            unset($_FILES['submission_file']);
        }
    }

    /** @return array<string, mixed> */
    private static function loadMyIdentity(): array
    {
        $userId = self::currentUserId();
        $context = Access::resolveAuthorizationContext($userId);
        if ($context === null || !is_string($context['role'] ?? null)) {
            throw new RuntimeException('authorization_context_unavailable');
        }

        $role = (string) $context['role'];
        if ($role === Access::ROLE_SYSTEM_ADMIN) {
            ClassIdentityHttp::abort(403, '系统管理员请使用独立管理控制台。');
        }

        if ($role === Access::ROLE_ANONYMOUS) {
            // Never expose the underlying Identity/Seat/account identifiers or
            // roster name in an Anonymous response.
            return [
                'role' => $role,
                'role_label' => '匿名席位',
                'identity_label' => '身份映射仅管理员可见',
                'account_state_label' => '正常',
                'relationship_label' => null,
                'seats' => [],
                'can_issue_family' => false,
                'can_activate_anonymous' => false,
            ];
        }

        if (!in_array($role, [Access::ROLE_CLASSMATE, Access::ROLE_TEACHER, Access::ROLE_FAMILY], true)) {
            throw new RuntimeException('unsupported_principal_role');
        }

        $identityId = (int) ($context['identity_id'] ?? 0);
        $accountId = (int) ($context['account_id'] ?? 0);
        if ($identityId <= 0 || $accountId <= 0) {
            throw new RuntimeException('principal_graph_incomplete');
        }

        $repository = Repository::fromPiwigo();
        $identity = $repository->fetchOne(
            'SELECT `roster_code`, `identity_type`, `real_name`, `state` FROM `'
            . $repository->table('identity') . '` WHERE `id` = ? LIMIT 1',
            [$identityId],
        );
        $account = $repository->fetchOne(
            'SELECT `requested_username`, `real_name`, `family_relationship`, `state` FROM `'
            . $repository->table('account') . '` WHERE `id` = ? AND `current_marker` = 1 LIMIT 1',
            [$accountId],
        );
        if ($identity === null || $account === null) {
            throw new RuntimeException('principal_graph_incomplete');
        }

        $seats = [];
        $canIssueFamily = false;
        $canActivateAnonymous = false;
        if ($role === Access::ROLE_CLASSMATE) {
            $rows = $repository->fetchAll(
                'SELECT s.`ordinal`, s.`seat_type`, s.`state`, '
                . 'a.`real_name` AS `account_real_name`, a.`family_relationship`, a.`state` AS `account_state`, '
                . '(SELECT MAX(t.`expires_at`) FROM `' . $repository->table('token') . '` t '
                . "WHERE t.`seat_id` = s.`id` AND t.`purpose` = 'FAMILY_INVITE' AND t.`state` = 'ISSUED') AS `invite_expires_at` "
                . 'FROM `' . $repository->table('seat') . '` s '
                . 'LEFT JOIN `' . $repository->table('account') . '` a '
                . 'ON a.`seat_id` = s.`id` AND a.`current_marker` = 1 '
                . 'WHERE s.`identity_id` = ? ORDER BY s.`ordinal` ASC',
                [$identityId],
            );

            foreach ($rows as $row) {
                $seatType = (string) ($row['seat_type'] ?? '');
                $seatState = (string) ($row['state'] ?? '');
                $canIssueFamily = $canIssueFamily || ($seatType === Access::ROLE_FAMILY && $seatState === 'AVAILABLE');
                $canActivateAnonymous = $canActivateAnonymous
                    || ($seatType === Access::ROLE_ANONYMOUS && $seatState === 'AVAILABLE');
                $seats[] = [
                    'ordinal' => (int) ($row['ordinal'] ?? 0),
                    'type_label' => self::roleLabel($seatType),
                    'state_label' => self::stateLabel($seatState),
                    'account_name' => self::nullableText($row['account_real_name'] ?? null),
                    'relationship_label' => self::relationshipLabel($row['family_relationship'] ?? null),
                    'account_state_label' => self::stateLabel((string) ($row['account_state'] ?? '')),
                    'invite_expires_at' => self::nullableText($row['invite_expires_at'] ?? null),
                ];
            }
        }

        $submissionRows = $role === Access::ROLE_FAMILY
            ? ClassIdentitySubmissionService::fromPiwigo()->mine($userId)
            : [];
        foreach ($submissionRows as &$submission) {
            $submission['state_label'] = match ((string) ($submission['state'] ?? '')) {
                'PENDING' => '待审核',
                'APPROVED' => '已通过',
                'REJECTED' => '已拒绝',
                default => '状态异常',
            };
            $submission['precision_label'] = ClassIdentityArchiveService::precisionLabel((string) ($submission['date_precision'] ?? 'UNKNOWN'));
        }
        unset($submission);

        return [
            'role' => $role,
            'role_label' => self::roleLabel($role),
            'identity_label' => (string) $identity['roster_code'] . ' · ' . (string) $identity['real_name'],
            'account_name' => (string) $account['requested_username'],
            'account_state_label' => self::stateLabel((string) $account['state']),
            'relationship_label' => self::relationshipLabel($account['family_relationship'] ?? null),
            'seats' => $seats,
            'can_issue_family' => $canIssueFamily,
            'can_activate_anonymous' => $canActivateAnonymous,
            'submissions' => $submissionRows,
        ];
    }

    private static function currentUserId(): int
    {
        global $user;

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            ClassIdentityHttp::abort(403, '禁止访问');
        }

        return $userId;
    }

    private static function isGuest(): bool
    {
        global $conf, $user;

        $userId = (int) ($user['id'] ?? 0);
        return $userId <= 0
            || $userId === (int) ($conf['guest_id'] ?? 0)
            || ($user['status'] ?? null) === 'guest';
    }

    private static function requireExactOrigin(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (!is_string($origin) || $origin === '' || $origin === 'null') {
            ClassIdentityHttp::abort(403, '请求来源未被允许');
        }

        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($fetchSite) && $fetchSite !== '' && $fetchSite !== 'same-origin') {
            ClassIdentityHttp::abort(403, '请求来源未被允许');
        }

        if (!ClassIdentityHttp::originMatchesConfiguredRoot($origin)) {
            ClassIdentityHttp::abort(403, '请求来源未被允许');
        }
    }

    private static function rejectSecretsInUrl(): void
    {
        foreach (array_keys($_GET) as $key) {
            if (!is_string($key)) {
                continue;
            }
            $normalized = strtolower(str_replace(['-', '.'], '_', $key));
            if (in_array($normalized, [
                'claim_code',
                'claimcode',
                'code',
                'invitation_code',
                'invitation',
                'invite_token',
                'selector',
                'validator',
                'token',
                'password',
                'password_confirmation',
                'secret',
            ], true)) {
                ClassIdentityHttp::abort(400, 'Secrets are not accepted in URLs.');
            }
        }
    }

    private static function postPlain(string $name, int $maxLength): string
    {
        $value = $_POST[$name] ?? null;
        if (!is_string($value)) {
            throw new InvalidArgumentException('invalid_input');
        }
        $value = trim($value);
        if ($value === '' || self::length($value) > $maxLength) {
            throw new InvalidArgumentException('invalid_input');
        }

        return $value;
    }

    private static function postOptional(string $name, int $maxLength): ?string
    {
        $value = $_POST[$name] ?? null;
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || self::length($value) > $maxLength || str_contains($value, "\0")) {
            throw new InvalidArgumentException('invalid_input');
        }
        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private static function pullSensitive(string $name, int $maxLength, bool $trim): string
    {
        $value = $_POST[$name] ?? null;
        unset($_POST[$name]);
        if (!is_string($value) || strlen($value) > $maxLength) {
            throw new InvalidArgumentException('invalid_input');
        }
        if ($trim) {
            $value = trim($value);
        }
        if ($value === '') {
            throw new InvalidArgumentException('invalid_input');
        }

        return $value;
    }

    /** @param list<string> $fields */
    private static function clearPostedFields(array $fields): void
    {
        foreach ($fields as $field) {
            unset($_POST[$field]);
        }
    }

    /** @param list<string> $fields */
    private static function clearSensitivePostedFields(array $fields): void
    {
        foreach ($fields as $field) {
            if (isset($_POST[$field]) && is_string($_POST[$field])) {
                self::wipe($_POST[$field]);
            }
            unset($_POST[$field]);
        }
    }

    private static function roleLabel(string $role): string
    {
        return match ($role) {
            Access::ROLE_CLASSMATE => '同学正式席位',
            Access::ROLE_TEACHER => '老师正式席位',
            Access::ROLE_FAMILY => '家庭席位',
            Access::ROLE_ANONYMOUS => '匿名席位',
            default => '未知',
        };
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            'ACTIVE' => '正常',
            'AVAILABLE' => '空闲',
            'INVITED' => '已发出邀请',
            'PROVISIONING' => '创建中',
            'FROZEN' => '已冻结',
            'DISABLED' => '已禁用',
            'RELEASED' => '已释放',
            default => $state === '' ? '—' : '异常',
        };
    }

    private static function relationshipLabel(mixed $relationship): ?string
    {
        if (!is_string($relationship) || $relationship === '') {
            return null;
        }

        return match ($relationship) {
            'FATHER' => '父亲',
            'MOTHER' => '母亲',
            'SIBLING' => '兄弟姐妹',
            'GUARDIAN' => '监护人',
            'OTHER_FAMILY' => '其他家属',
            default => '家属',
        };
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function consumePublicAttempt(
        string $purpose,
        string $codeField,
        ?string $rosterField = null,
    ): bool {
        $rawCode = isset($_POST[$codeField]) && is_string($_POST[$codeField])
            ? substr($_POST[$codeField], 0, 160)
            : '';
        $roster = $rosterField !== null
            && isset($_POST[$rosterField])
            && is_string($_POST[$rosterField])
                ? substr($_POST[$rosterField], 0, 128)
                : '';
        $sourceIp = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? $_SERVER['REMOTE_ADDR']
            : '';

        try {
            $decision = RateLimiter::checkFromPiwigo(
                $purpose,
                $sourceIp,
                $rawCode,
                $rosterField === null ? null : $roster,
            );
        } finally {
            self::wipe($rawCode);
            self::wipe($roster);
        }

        if (($decision['allowed'] ?? false) === true) {
            return true;
        }

        if (!headers_sent()) {
            header('Retry-After: ' . (int) ($decision['retry_after'] ?? 60), true);
        }
        http_response_code(
            ($decision['state'] ?? null) === RateLimiter::STATE_LIMITED ? 429 : 503,
        );
        self::$view['CA_ERROR'] = '请求过于频繁或暂时无法安全处理，未创建账号。请稍后重试。';
        self::clearSensitivePostedFields([
            'claim_code',
            'invitation_code',
            'password',
            'password_confirmation',
        ]);

        return false;
    }

    private static function logFailure(string $operation, Throwable $error): void
    {
        // Log only bounded operation/error class identifiers. Exception
        // messages may originate in third-party account validation and are
        // intentionally excluded.
        error_log('ClassIdentity public ' . $operation . ' failed [' . get_class($error) . ']');
    }

    private static function wipe(string &$value): void
    {
        if ($value === '') {
            return;
        }
        if (function_exists('sodium_memzero')) {
            sodium_memzero($value);
            return;
        }
        $value = str_repeat("\0", strlen($value));
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
