<?php

declare(strict_types=1);

namespace ClassIdentity;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Authenticated, localhost-only bridge from Piwigo's login form to the owned
 * Photo UI. Piwigo intentionally accepts redirect targets only inside its own
 * origin; this exact internal route performs the second, bounded redirect
 * after the freshly logged-in ClassIdentity principal has been verified.
 */
final class PhotoAppRedirect
{
    private const TOKEN = 'class-archive-photo-app';

    public static function onSectionInit(): void
    {
        global $page, $tokens;
        if (!is_array($tokens) || ($tokens[0] ?? null) !== self::TOKEN) {
            return;
        }
        // Piwigo's pretty-route parser may retain one or more empty trailing
        // tokens. Accept only those empty parser artifacts; any real suffix is
        // a different route and must never inherit this redirect.
        $unexpected = array_filter(
            array_slice($tokens, 1),
            static fn(mixed $token): bool => is_scalar($token) && (string) $token !== '',
        );
        if ($unexpected !== []) {
            return;
        }
        $page['class_archive_photo_app_redirect'] = true;
        $page['section'] = 'class_archive_photo_app_redirect';
        $page['section_title'] = '班级相册';
        $page['title'] = $page['section_title'];
        $page['is_homepage'] = false;
        $page['is_external'] = true;
        $page['items'] = [];
        $page['meta_robots']['noindex'] = 1;
    }

    public static function onBeginIndex(): void
    {
        global $page, $user;
        if (($page['class_archive_photo_app_redirect'] ?? false) !== true) {
            return;
        }
        \ClassIdentityHttp::noStore();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            header('Allow: GET');
            \ClassIdentityHttp::abort(405, 'Method Not Allowed');
        }
        $context = Access::resolveAuthorizationContext((int) ($user['id'] ?? 0));
        if (!is_array($context) || !is_string($context['role'] ?? null)) {
            \ClassIdentityHttp::abort(403, '请先登录。');
        }
        $port = (string) (getenv('CLASS_ARCHIVE_COMPAT_HTTP_PORT') ?: '');
        if (preg_match('/\A[1-9][0-9]{0,4}\z/D', $port) !== 1 || (int) $port > 65535) {
            \ClassIdentityHttp::abort(503, '照片界面暂时不可用。');
        }
        header('Location: http://127.0.0.1:' . $port . '/photos', true, 303);
        exit;
    }
}
