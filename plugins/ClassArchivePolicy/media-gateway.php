<?php

declare(strict_types=1);

if (($_SERVER['CLASS_ARCHIVE_MEDIA_GATEWAY'] ?? null) !== '1') {
    http_response_code(404);
    exit;
}
if (!in_array((string) ($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

function classArchiveMediaResponseHeaders(): void
{
    header('Cache-Control: private, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function classArchiveMediaDeny(int $status = 403): never
{
    classArchiveMediaResponseHeaders();
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo match ($status) {
        404 => 'Media not found.',
        503 => 'Media temporarily unavailable.',
        default => 'Media access denied.',
    };
    exit;
}

function classArchiveMediaContentType(string $path): string
{
    return match (strtolower(pathinfo(rawurldecode($path), PATHINFO_EXTENSION))) {
        'jpg', 'jpeg', 'jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'tif', 'tiff' => 'image/tiff',
        default => 'application/octet-stream',
    };
}

try {
    chdir('/var/www/html/piwigo') || throw new RuntimeException('root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassArchivePolicy/src/MediaGuard.php';

    $resolved = ClassArchiveMediaGuard::resolveRequest(
        (string) ($_SERVER['CLASS_ARCHIVE_MEDIA_KIND'] ?? '')
    );
    $request = $resolved['request'];
    $decision = ClassArchiveMediaGuard::authorize($request, $resolved['image']);
    if (!$decision->allowed) {
        classArchiveMediaDeny();
    }
    ClassArchiveMediaGuard::assertDeliveryTarget($request);

    classArchiveMediaResponseHeaders();
    header('Content-Type: ' . classArchiveMediaContentType($request->internalUri));
    header('X-Accel-Redirect: ' . $request->internalUri);
    if ($request->downloadName !== null && isset($_GET['download'])) {
        $safeAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $request->downloadName);
        if (!is_string($safeAscii) || $safeAscii === '') {
            $safeAscii = 'photo';
        }
        header(
            'Content-Disposition: attachment; filename="' . $safeAscii . '"; filename*=UTF-8\'\''
            . rawurlencode($request->downloadName)
        );
    }
    exit;
} catch (ClassArchiveMediaUnavailable) {
    classArchiveMediaDeny(503);
} catch (DomainException) {
    classArchiveMediaDeny(404);
} catch (Throwable $exception) {
    error_log('ClassArchive MediaGuard denied request: internal_error');
    classArchiveMediaDeny(503);
}
