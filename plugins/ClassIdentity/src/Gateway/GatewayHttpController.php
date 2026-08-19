<?php

declare(strict_types=1);

namespace ClassIdentity\Gateway;

use ClassIdentity\ClassArchivePhoto;

defined('PHPWG_ROOT_PATH') or die('Hacking attempt!');

/**
 * Same-origin Class Archive Gateway HTTP boundary.
 *
 * This controller intentionally has no media endpoint. Every photo projection
 * carries only the explicit MEDIAGUARD_REQUIRED delivery contract, so neither
 * Piwigo nor Immich byte URLs can accidentally become a new authorization
 * path. The handler exits from Piwigo's loc_begin_index hook before template
 * work begins and returns generic failures on every uncertain identity,
 * mapping, source or serialization condition.
 */
final class GatewayHttpController
{
    private const ROOT_TOKEN = 'class-archive-api';

    /** @var list<string> */
    private const SIMPLE_ROUTES = ['photos', 'timeline', 'albums', 'people', 'memories', 'me'];

    public static function onSectionInit(): void
    {
        global $conf, $page, $tokens, $user;

        if (!is_array($tokens) || ($tokens[0] ?? null) !== self::ROOT_TOKEN) {
            return;
        }

        $segments = [];
        foreach (array_slice($tokens, 1) as $token) {
            if (!is_string($token) || $token === '' || preg_match('/\A[a-z0-9-]{1,64}\z/D', $token) !== 1) {
                $segments = ['not-found'];
                break;
            }
            $segments[] = $token;
        }

        $page['class_archive_gateway_segments'] = $segments;
        $page['section'] = 'class_archive_gateway';
        $page['section_title'] = 'Class Archive Gateway';
        $page['title'] = $page['section_title'];
        $page['is_homepage'] = false;
        $page['is_external'] = true;
        $page['items'] = [];
        $page['meta_robots']['noindex'] = 1;

        // Piwigo's baseline gallery is private and would otherwise redirect
        // its reserved guest before loc_begin_index can return a generic API
        // 403. This changes only the in-memory shell status; Access still sees
        // the reserved guest id and the controller rejects it below.
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $userId === (int) ($conf['guest_id'] ?? 0)) {
            $user['status'] = 'generic';
            $page['class_archive_gateway_guest_shell_only'] = true;
        }
    }

    public static function onBeginIndex(): void
    {
        global $page;

        $segments = $page['class_archive_gateway_segments'] ?? null;
        if (!is_array($segments)) {
            return;
        }

        self::setSecurityHeaders();
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
            header('Allow: GET');
            self::respond(405, ['error' => '仅支持读取请求']);
        }
        self::requireSameOriginWhenPresent();

        try {
            [$route, $photoId, $searchQuery] = self::parseRoute($segments);
            $gateway = new GatewayService(
                new ClassIdentityAdapter(),
                PiwigoGatewayAdapter::fromPiwigo(),
                new NullImmichAdapter(),
            );
            $response = match ($route) {
                'photos' => $photoId === null ? $gateway->photos() : self::knownPhoto($gateway, $photoId),
                'timeline' => $gateway->timeline(),
                'albums' => $gateway->albums(),
                'people' => $gateway->people(),
                'memories' => $gateway->memories(),
                'search' => $gateway->search($searchQuery ?? ''),
                'me' => $gateway->me(),
                default => throw new \InvalidArgumentException('class_archive_gateway_route_invalid'),
            };
            self::respond(200, $response);
        } catch (\InvalidArgumentException) {
            self::respond(400, ['error' => '请求格式无效']);
        } catch (\RuntimeException $error) {
            $code = $error->getMessage();
            if ($code === 'class_archive_gateway_principal_unresolved') {
                self::respond(403, ['error' => '禁止访问']);
            }
            if ($code === 'class_archive_gateway_photo_not_found') {
                self::respond(404, ['error' => '资源不存在']);
            }
            if ($code === 'class_archive_gateway_route_not_found') {
                self::respond(404, ['error' => '资源不存在']);
            }
            self::respond(503, ['error' => '数据暂时无法安全确认']);
        } catch (\Throwable) {
            self::respond(503, ['error' => '数据暂时无法安全确认']);
        }
    }

    /** @return array{0:string,1:?string,2:?string} */
    private static function parseRoute(array $segments): array
    {
        if ($segments === []) {
            throw new \InvalidArgumentException('class_archive_gateway_route_invalid');
        }
        $route = $segments[0] ?? null;
        if (!is_string($route)) {
            throw new \InvalidArgumentException('class_archive_gateway_route_invalid');
        }

        if (in_array($route, self::SIMPLE_ROUTES, true) && count($segments) === 1) {
            self::requireExactQuery([]);
            return [$route, null, null];
        }
        if ($route === 'photos' && count($segments) === 2 && is_string($segments[1])) {
            ClassArchivePhoto::idToBinary($segments[1]);
            self::requireExactQuery([]);
            return ['photos', $segments[1], null];
        }
        if ($route === 'search' && count($segments) === 1) {
            $query = self::requireExactQuery(['q'])['q'] ?? null;
            if (!is_string($query)) {
                throw new \InvalidArgumentException('class_archive_gateway_search_missing');
            }
            return ['search', null, $query];
        }

        throw new \RuntimeException('class_archive_gateway_route_not_found');
    }

    /** @return array<string,string> */
    private static function requireExactQuery(array $allowed): array
    {
        $rawQuery = $_SERVER['QUERY_STRING'] ?? '';
        if (!is_string($rawQuery)) {
            throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
        }
        $result = [];
        if ($rawQuery === '') {
            return $result;
        }

        foreach (explode('&', $rawQuery) as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part, 2);
            $key = rawurldecode(str_replace('+', ' ', $pair[0]));
            if (str_starts_with($key, '/' . self::ROOT_TOKEN . '/')) {
                // Piwigo's question-mark URL mode encodes the route as the
                // first key. Nginx's /api rewrite uses the same safe form.
                if (count($pair) !== 1) {
                    throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
                }
                continue;
            }
            if (!in_array($key, $allowed, true) || count($pair) !== 2 || isset($result[$key])) {
                throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
            }
            $value = rawurldecode(str_replace('+', ' ', $pair[1]));
            if ($value === '' || strlen($value) > 190 || str_contains($value, "\0")) {
                throw new \InvalidArgumentException('class_archive_gateway_query_invalid');
            }
            $result[$key] = $value;
        }

        foreach ($allowed as $key) {
            if (!isset($result[$key])) {
                throw new \InvalidArgumentException('class_archive_gateway_query_missing');
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function knownPhoto(GatewayService $gateway, string $classPhotoId): array
    {
        $photo = $gateway->photo($classPhotoId);
        if ($photo === null) {
            throw new \RuntimeException('class_archive_gateway_photo_not_found');
        }
        return $photo;
    }

    private static function requireSameOriginWhenPresent(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        if (is_string($origin) && $origin !== '' && !\ClassIdentityHttp::originMatchesConfiguredRoot($origin)) {
            self::respond(403, ['error' => '请求来源未被允许']);
        }
        $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
        if (is_string($fetchSite) && $fetchSite !== '' && $fetchSite !== 'same-origin') {
            self::respond(403, ['error' => '请求来源未被允许']);
        }
    }

    private static function setSecurityHeaders(): void
    {
        \ClassIdentityHttp::noStore();
        header('Content-Type: application/json; charset=utf-8');
        header('Vary: Cookie', false);
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
    }

    /** @param array<string,mixed> $payload */
    private static function respond(int $status, array $payload): never
    {
        http_response_code($status);
        try {
            echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Throwable) {
            http_response_code(503);
            echo '{"error":"数据暂时无法安全确认"}';
        }
        exit;
    }
}
