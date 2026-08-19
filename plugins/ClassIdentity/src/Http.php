<?php

declare(strict_types=1);

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

use ClassIdentity\Access;
use ClassIdentity\Audit;

/**
 * Small HTTP boundary shared by the ClassIdentity business admin pages.
 *
 * It deliberately does not trust Piwigo's admin route check alone: a request
 * must also resolve to an active ClassIdentity SYSTEM_ADMIN principal.
 */
final class ClassIdentityHttp
{
    private const FLASH_KEY = 'class_identity_admin_flash';

    /** @param list<string> $allowedTabs */
    public static function requestedTab(array $allowedTabs, string $default = 'dashboard'): string
    {
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? $_GET['tab'] : '';
        if ($tab === '') {
            $page = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : '';
            $prefix = 'plugin-ClassIdentity-';
            if (str_starts_with($page, $prefix)) {
                $tab = substr($page, strlen($prefix));
            }
        }
        if ($tab === '') {
            $tab = $default;
        }

        return in_array($tab, $allowedTabs, true) ? $tab : $default;
    }

    public static function noStore(): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
    }

    /**
     * Terminal one-time credential response. The secret is never placed in a
     * URL, session, log, template cache or redirect. All dynamic values are
     * validated and escaped before the response is emitted.
     *
     * @param array{code:mixed,expires_at:mixed,generation:mixed,seat_id:mixed} $issued
     */
    public static function renderIssuedClaim(array $issued, string $returnUrl): never
    {
        $code = $issued['code'] ?? null;
        $expiresAt = $issued['expires_at'] ?? null;
        $generation = filter_var($issued['generation'] ?? null, FILTER_VALIDATE_INT);
        $seatId = filter_var($issued['seat_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($code)
            || !preg_match('/\A[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}\z/D', $code)
            || !is_string($expiresAt)
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $expiresAt)
            || !is_int($generation)
            || $generation <= 0
            || !is_int($seatId)
            || $seatId <= 0
            || $returnUrl === ''
            || str_contains($returnUrl, "\r")
            || str_contains($returnUrl, "\n")
        ) {
            self::abort(503, '一次性凭据页面暂时不可用');
        }

        self::noStore();
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'; form-action 'none'");
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $safeCode = $escape($code);
        $safeExpiry = $escape($expiresAt);
        $safeReturnUrl = $escape($returnUrl);
        $safeGeneration = (string) $generation;
        $safeSeatId = (string) $seatId;

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>一次性认领码 · 班级数字档案馆</title>'
            . '<style>body{margin:0;background:#f5f7fa;color:#172033;font:16px/1.6 system-ui,sans-serif}'
            . 'main{max-width:760px;margin:8vh auto;padding:28px;background:#fff;border:1px solid #d9dee8;border-radius:16px}'
            . 'code{display:block;padding:18px;background:#111827;color:#fff;border-radius:10px;font:700 17px/1.5 ui-monospace,monospace;overflow-wrap:anywhere;user-select:all}'
            . 'a{display:inline-block;margin-top:18px;padding:10px 14px;border-radius:8px;background:#25324a;color:#fff;text-decoration:none;font-weight:700}'
            . '.meta{color:#677287}</style></head><body><main>'
            . '<h1>认领码已生成</h1><p>旧认领码已永久失效。请现在复制并通过安全渠道交给对应成员；离开本页后只能重新签发。</p>'
            . '<code class="ca-admin__code">' . $safeCode . '</code>'
            . '<p class="meta">席位 #' . $safeSeatId . ' · 第 ' . $safeGeneration . ' 代'
            . ' · 到期 ' . $safeExpiry . ' UTC</p>'
            . '<a href="' . $safeReturnUrl . '">我已安全保存，返回管理控制台</a>'
            . '</main></body></html>';
        exit;
    }

    /** @param array{code:mixed,expires_at:mixed,seat_id:mixed} $issued */
    public static function renderIssuedFamilyInvitation(array $issued, string $returnUrl): never
    {
        $code = $issued['code'] ?? null;
        $expiresAt = $issued['expires_at'] ?? null;
        $seatId = filter_var($issued['seat_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($code)
            || !preg_match('/\A[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{32,}\z/D', $code)
            || !is_string($expiresAt)
            || !preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/D', $expiresAt)
            || !is_int($seatId)
            || $seatId <= 0
        ) {
            self::abort(503, '一次性凭据页面暂时不可用');
        }

        self::renderOneTimeCredential(
            '家庭邀请已生成',
            '请现在复制并通过安全渠道交给对应家属；离开本页后只能撤销并重新生成。',
            [
                '家庭邀请代码' => $code,
                '到期时间（UTC）' => $expiresAt,
                '席位' => '#' . $seatId,
            ],
            $returnUrl,
        );
    }

    /** @param array{username:mixed,password:mixed,user_id:mixed,seat_id:mixed} $credentials */
    public static function renderActivatedAnonymous(array $credentials, string $returnUrl): never
    {
        $username = $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;
        $userId = filter_var($credentials['user_id'] ?? null, FILTER_VALIDATE_INT);
        $seatId = filter_var($credentials['seat_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($username)
            || !preg_match('/\Aanon_[a-f0-9]{20}\z/D', $username)
            || !is_string($password)
            || !preg_match('/\A[A-Za-z0-9_-]{24,128}\z/D', $password)
            || !is_int($userId)
            || $userId <= 0
            || !is_int($seatId)
            || $seatId <= 0
        ) {
            self::abort(503, '一次性凭据页面暂时不可用');
        }

        self::renderOneTimeCredential(
            '匿名席位已激活',
            '这是独立账号。请立即安全保存登录信息；离开本页后系统不会再次显示密码。',
            [
                '登录用户名' => $username,
                '密码' => $password,
            ],
            $returnUrl,
        );
    }

    /** @param array<string, mixed> $mapping */
    public static function renderAnonymousResolution(array $mapping, string $returnUrl): never
    {
        $alias = $mapping['alias'] ?? null;
        $classmate = $mapping['classmate_id'] ?? null;
        $realName = $mapping['real_name'] ?? null;
        $seatId = filter_var($mapping['seat_id'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($alias) || $alias === '' || strlen($alias) > 128
            || !is_string($classmate) || $classmate === '' || strlen($classmate) > 64
            || !is_string($realName) || $realName === '' || strlen($realName) > 190
            || !is_int($seatId) || $seatId <= 0
        ) {
            self::abort(503, '匿名身份结果暂时不可用');
        }
        self::renderOneTimeCredential(
            '匿名身份解析结果',
            '本次查看已写入操作审计。关闭本页后不会把映射结果保存在普通页面、会话或 URL 中。',
            [
                '对外匿名名' => $alias,
                '班级成员编号' => $classmate,
                '成员姓名' => $realName,
                '匿名席位' => '#' . $seatId,
            ],
            $returnUrl,
        );
    }

    /** @param array<string, string> $fields */
    private static function renderOneTimeCredential(
        string $title,
        string $description,
        array $fields,
        string $returnUrl,
    ): never {
        if (
            $fields === []
            || $returnUrl === ''
            || str_contains($returnUrl, "\r")
            || str_contains($returnUrl, "\n")
        ) {
            self::abort(503, '一次性凭据页面暂时不可用');
        }
        foreach ($fields as $label => $value) {
            if ($label === '' || $value === '' || strlen($label) > 80 || strlen($value) > 1024) {
                self::abort(503, '一次性凭据页面暂时不可用');
            }
        }

        self::noStore();
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'; form-action 'none'");
        $escape = static fn(string $value): string => htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        );
        $fieldHtml = '';
        foreach ($fields as $label => $value) {
            $fieldHtml .= '<section><h2>' . $escape($label) . '</h2><code class="ca-public__secret">'
                . $escape($value) . '</code></section>';
        }

        echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $escape($title) . ' · 班级数字档案馆</title>'
            . '<style>body{margin:0;background:#f5f7fa;color:#172033;font:16px/1.6 system-ui,sans-serif}'
            . 'main{max-width:760px;margin:8vh auto;padding:28px;background:#fff;border:1px solid #d9dee8;border-radius:16px}'
            . 'h2{font-size:14px;margin:20px 0 6px;color:#677287}code{display:block;padding:14px;background:#111827;color:#fff;border-radius:10px;font:700 17px/1.5 ui-monospace,monospace;overflow-wrap:anywhere;user-select:all}'
            . 'a{display:inline-block;margin-top:22px;padding:10px 14px;border-radius:8px;background:#25324a;color:#fff;text-decoration:none;font-weight:700}</style>'
            . '</head><body><main><h1>' . $escape($title) . '</h1><p>' . $escape($description) . '</p>'
            . $fieldHtml . '<a href="' . $escape($returnUrl) . '">我已安全保存，返回</a>'
            . '</main></body></html>';
        exit;
    }

    public static function requireSystemAdmin(): void
    {
        global $user;

        $userId = isset($user['id']) ? (int) $user['id'] : 0;
        $status = isset($user['status']) && is_string($user['status']) ? $user['status'] : '';
        $coreAllows = $userId > 0 && in_array($status, ['admin', 'webmaster'], true);
        $identityAllows = class_exists(Access::class)
            && Access::isActiveSystemAdmin($userId);

        if (!$coreAllows || !$identityAllows) {
            self::abort(403, '禁止访问');
        }
    }

    public static function requireMutation(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            self::abort(405, 'Method Not Allowed');
        }

        self::requireSameOriginWhenPresent();

        $submitted = $_POST['pwg_token'] ?? null;
        if (!is_string($submitted) || $submitted === '') {
            self::abort(403, 'Invalid request token');
        }

        $expected = (string) get_pwg_token();
        if ($expected === '' || !hash_equals($expected, $submitted)) {
            self::abort(403, 'Invalid request token');
        }
    }

    /**
     * Validate a browser Origin against the configured site origin.
     *
     * Local development commonly switches between localhost and
     * 127.0.0.1. They are accepted only as an explicit loopback alias pair;
     * arbitrary Host/Origin values never become trusted by this convenience.
     */
    public static function originMatchesConfiguredRoot(string $origin): bool
    {
        if ($origin === '' || strtolower($origin) === 'null') {
            return false;
        }

        $originParts = parse_url($origin);
        $rootParts = parse_url(get_absolute_root_url());
        if (!is_array($originParts) || !is_array($rootParts)) {
            return false;
        }
        if (
            isset($originParts['user']) || isset($originParts['pass'])
            || isset($originParts['path']) || isset($originParts['query'])
            || isset($originParts['fragment'])
        ) {
            return false;
        }

        $originScheme = strtolower((string) ($originParts['scheme'] ?? ''));
        $originHost = strtolower((string) ($originParts['host'] ?? ''));
        $rootScheme = strtolower((string) ($rootParts['scheme'] ?? ''));
        $rootHost = strtolower((string) ($rootParts['host'] ?? ''));
        $originPort = isset($originParts['port'])
            ? (int) $originParts['port']
            : self::defaultPort($originScheme);
        $rootPort = isset($rootParts['port'])
            ? (int) $rootParts['port']
            : self::defaultPort($rootScheme);

        if (
            $originScheme === '' || $originHost === ''
            || !hash_equals($rootScheme, $originScheme)
        ) {
            return false;
        }

        if ($rootPort === $originPort && hash_equals($rootHost, $originHost)) {
            return true;
        }

        $loopbackHosts = ['localhost', '127.0.0.1', '::1', '[::1]'];
        if (!in_array($rootHost, $loopbackHosts, true)
            || !in_array($originHost, $loopbackHosts, true)
        ) {
            return false;
        }

        // Piwigo's configured root has no knowledge of a Docker host-port
        // mapping, so localhost development can legitimately be served at
        // http://127.0.0.1:8090 while get_absolute_root_url() says
        // http://localhost/. Never accept an arbitrary loopback Origin just
        // because both names are aliases: it must exactly match the browser's
        // request Host and port. A site on localhost:3000 therefore cannot
        // forge a state-changing request to this service on localhost:8090.
        return self::originMatchesRequestHost($originScheme, $originHost, $originPort);
    }

    public static function requireReason(string $field = 'reason'): string
    {
        $reason = $_POST[$field] ?? null;
        unset($_POST[$field]);
        try {
            $validated = Audit::validateReason($reason, true);
        } catch (InvalidArgumentException) {
            if (is_string($reason)) {
                self::wipe($reason);
            }
            self::abort(400, '操作原因包含不允许的内容。');
        }

        if (!is_string($validated)) {
            self::abort(400, '操作原因无效。');
        }

        return $validated;
    }

    public static function postString(string $name, int $maxLength, bool $required = false): string
    {
        $value = $_POST[$name] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('请求字段格式无效。');
        }

        $value = trim($value);
        if ($required && $value === '') {
            throw new InvalidArgumentException('请填写所有必填字段。');
        }

        if (self::length($value) > $maxLength) {
            throw new InvalidArgumentException('请求字段过长。');
        }

        return $value;
    }

    public static function postPositiveInt(string $name): int
    {
        $value = $_POST[$name] ?? null;
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            throw new InvalidArgumentException('请求对象无效。');
        }

        $id = (int) $value;
        if ($id < 1) {
            throw new InvalidArgumentException('请求对象无效。');
        }

        return $id;
    }

    public static function queryPositiveInt(string $name): ?int
    {
        if (!isset($_GET[$name])) {
            return null;
        }

        $value = $_GET[$name];
        if (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value)) {
            self::abort(400, '请求无效');
        }

        return (int) $value;
    }

    public static function redirectTo(string $tab, array $query = []): never
    {
        $url = get_root_url() . 'admin.php?page=plugin-ClassIdentity-' . rawurlencode($tab);
        foreach ($query as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_int($value))) {
                continue;
            }
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode((string) $value);
        }

        header('Location: ' . $url, true, 303);
        exit;
    }

    public static function flash(string $kind, string $message): void
    {
        if (!isset($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            $_SESSION[self::FLASH_KEY] = [];
        }

        $_SESSION[self::FLASH_KEY][] = [
            'kind' => in_array($kind, ['success', 'error', 'warning'], true) ? $kind : 'warning',
            'message' => self::truncate($message, 500),
        ];
    }

    /** @return list<array{kind:string,message:string}> */
    public static function consumeFlash(): array
    {
        $messages = $_SESSION[self::FLASH_KEY] ?? [];
        unset($_SESSION[self::FLASH_KEY]);

        if (!is_array($messages)) {
            return [];
        }

        $safe = [];
        foreach ($messages as $message) {
            if (!is_array($message) || !is_string($message['message'] ?? null)) {
                continue;
            }
            $safe[] = [
                'kind' => in_array($message['kind'] ?? '', ['success', 'error', 'warning'], true)
                    ? $message['kind']
                    : 'warning',
                'message' => self::truncate($message['message'], 500),
            ];
        }

        return $safe;
    }

    public static function abort(int $status, string $message): never
    {
        self::noStore();
        http_response_code($status);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
        exit;
    }

    private static function requireSameOriginWhenPresent(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        // Chromium can emit the literal `null` Origin for a same-document
        // HTML form navigation. It carries no origin assertion, so handle it
        // exactly like an absent Origin: the mandatory per-session Piwigo
        // CSRF token remains the authorization proof. A concrete foreign
        // Origin is still rejected below; public Claim/Invite routes keep
        // their stricter explicit-Origin requirement.
        if ($origin === '' || (is_string($origin) && strtolower($origin) === 'null')) {
            return;
        }
        if (!is_string($origin)) {
            self::abort(403, '请求来源未被允许');
        }

        if (!self::originMatchesConfiguredRoot($origin)) {
            self::abort(403, '请求来源未被允许');
        }
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function originMatchesRequestHost(string $scheme, string $originHost, int $originPort): bool
    {
        $requestHost = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($requestHost) || $requestHost === '' || str_contains($requestHost, '/')) {
            return false;
        }

        $requestParts = parse_url($scheme . '://' . $requestHost);
        if (!is_array($requestParts)
            || isset($requestParts['user']) || isset($requestParts['pass'])
            || isset($requestParts['path']) || isset($requestParts['query']) || isset($requestParts['fragment'])
        ) {
            return false;
        }

        $requestHostName = strtolower((string) ($requestParts['host'] ?? ''));
        $requestPort = isset($requestParts['port'])
            ? (int) $requestParts['port']
            : self::defaultPort($scheme);

        return $requestHostName !== ''
            && $requestPort === $originPort
            && hash_equals($originHost, $requestHostName);
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function truncate(string $value, int $limit): string
    {
        if (self::length($value) <= $limit) {
            return $value;
        }

        return function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
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
}
