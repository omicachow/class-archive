<?php

declare(strict_types=1);

if (
    ($_SERVER['CLASS_ARCHIVE_IDENTITY_DERIVATIVE_FALLBACK'] ?? null) !== '1'
    || ($_SERVER['CLASS_ARCHIVE_CORE_NO_CHANGE'] ?? null) !== 'No change'
) {
    http_response_code(404);
    exit;
}
if (!in_array((string) ($_SERVER['REQUEST_METHOD'] ?? ''), ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

function classArchiveIdentityDerivativeHeaders(): void
{
    header('Cache-Control: private, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Vary: Cookie');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
}

function classArchiveIdentityDerivativeDeny(int $status): never
{
    classArchiveIdentityDerivativeHeaders();
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $status === 403 ? 'Media access denied.' : 'Media temporarily unavailable.';
    exit;
}

function classArchiveIdentityDerivativeEncodePath(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function classArchiveIdentityDerivativeContentType(string $path): string
{
    return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg', 'jpe' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'tif', 'tiff' => 'image/tiff',
        default => 'application/octet-stream',
    };
}

function classArchiveIdentityDerivativeEnsureParent(string $root, string $relative): string
{
    $resolvedRoot = realpath($root);
    if ($resolvedRoot === false || !is_dir($resolvedRoot) || is_link($root)) {
        throw new RuntimeException('derivative_root_unavailable');
    }
    $rootPrefix = rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $directory = dirname($relative);
    if ($directory === '.' || $directory === '') {
        throw new RuntimeException('derivative_parent_invalid');
    }

    $cursor = $resolvedRoot;
    foreach (explode('/', $directory) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            throw new RuntimeException('derivative_parent_invalid');
        }
        $next = $cursor . DIRECTORY_SEPARATOR . $segment;
        if (!file_exists($next)) {
            $oldUmask = umask(0007);
            try {
                $created = @mkdir($next, 0770);
            } finally {
                umask($oldUmask);
            }
            if (!$created && !is_dir($next)) {
                throw new RuntimeException('derivative_parent_create_failed');
            }
        }
        if (!is_dir($next) || is_link($next)) {
            throw new RuntimeException('derivative_parent_unsafe');
        }
        $resolved = realpath($next);
        if ($resolved === false || !str_starts_with($resolved, $rootPrefix)) {
            throw new RuntimeException('derivative_parent_outside_root');
        }
        $cursor = $resolved;
    }

    return $cursor;
}

function classArchiveIdentityDerivativeAssertFile(string $root, string $path): string
{
    $resolvedRoot = realpath($root);
    $resolved = realpath($path);
    if (
        $resolvedRoot === false
        || $resolved === false
        || !is_file($resolved)
        || is_link($path)
        || !str_starts_with($resolved, rtrim($resolvedRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        throw new RuntimeException('derivative_file_unsafe');
    }

    return $resolved;
}

$bootstrapBufferLevel = ob_get_level();
try {
    chdir('/var/www/html/piwigo') || throw new RuntimeException('piwigo_root_unavailable');
    define('PHPWG_ROOT_PATH', './');
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassArchivePolicy/src/MediaGuard.php';

    $resolved = ClassArchiveMediaGuard::resolveRequest('derivative_path');
    $request = $resolved['request'];
    if ($request->variant !== 'derivative' || $request->derivativePath === null) {
        throw new DomainException('identity_derivative_request_invalid');
    }
    ClassArchiveMediaGuard::assertDeliveryTarget($request);
    $decision = ClassArchiveMediaGuard::authorize($request, $resolved['image']);
    if (!$decision->allowed) {
        classArchiveIdentityDerivativeDeny(403);
    }

    $derivativeRoot = '/var/www/html/piwigo/_data/i';
    $sourceRoot = '/var/www/html/piwigo';
    $sourcePath = classArchiveIdentityDerivativeAssertFile(
        $sourceRoot . '/' . explode('/', $request->sourcePath, 2)[0],
        $sourceRoot . '/' . $request->sourcePath,
    );
    $parent = classArchiveIdentityDerivativeEnsureParent($derivativeRoot, $request->derivativePath);
    $candidate = $derivativeRoot . '/' . $request->derivativePath;

    $lockPath = sys_get_temp_dir() . '/class-archive-derivative-' . hash('sha256', $request->derivativePath) . '.lock';
    $lock = fopen($lockPath, 'c');
    if ($lock === false || !@chmod($lockPath, 0600) || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new RuntimeException('derivative_lock_failed');
    }

    $temporary = null;
    try {
        clearstatcache(true, $candidate);
        if (file_exists($candidate)) {
            classArchiveIdentityDerivativeAssertFile($derivativeRoot, $candidate);
        } else {
            require_once PHPWG_ROOT_PATH . 'admin/include/image.class.php';
            $extension = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            $filename = pathinfo($candidate, PATHINFO_FILENAME);
            if (!preg_match('/\A[A-Za-z0-9]+\z/D', $extension) || $filename === '') {
                throw new RuntimeException('derivative_extension_invalid');
            }
            $temporary = $parent . '/.' . $filename . '.class-archive-'
                . bin2hex(random_bytes(8)) . '.' . $extension;

            $sourceSize = @getimagesize($sourcePath);
            if (!is_array($sourceSize) || (int) $sourceSize[0] <= 0 || (int) $sourceSize[1] <= 0) {
                throw new RuntimeException('source_dimensions_invalid');
            }

            $image = new pwg_image($sourcePath);
            try {
                $image->set_compression_quality(ImageStdParams::$quality);
                $image->strip();
                $image->write($temporary);
            } finally {
                $image->destroy();
            }

            clearstatcache(true, $temporary);
            $outputSize = is_file($temporary) && !is_link($temporary) ? @getimagesize($temporary) : false;
            if (
                !is_array($outputSize)
                || (int) $outputSize[0] !== (int) $sourceSize[0]
                || (int) $outputSize[1] !== (int) $sourceSize[1]
                || filesize($temporary) <= 0
                || !chmod($temporary, 0660)
            ) {
                throw new RuntimeException('identity_derivative_validation_failed');
            }
            if (!rename($temporary, $candidate)) {
                throw new RuntimeException('identity_derivative_publish_failed');
            }
            $temporary = null;
            if (!chmod($candidate, 0660)) {
                throw new RuntimeException('identity_derivative_permissions_failed');
            }
        }
    } finally {
        if ($temporary !== null && (is_file($temporary) || is_link($temporary))) {
            @unlink($temporary);
        }
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    classArchiveIdentityDerivativeAssertFile($derivativeRoot, $candidate);
    classArchiveIdentityDerivativeHeaders();
    header('Content-Type: ' . classArchiveIdentityDerivativeContentType($request->derivativePath));
    header(
        'X-Accel-Redirect: /_class_archive_internal/derivative/'
        . classArchiveIdentityDerivativeEncodePath($request->derivativePath)
    );
    exit;
} catch (DomainException) {
    while (ob_get_level() > $bootstrapBufferLevel) {
        ob_end_clean();
    }
    classArchiveIdentityDerivativeDeny(403);
} catch (Throwable $exception) {
    while (ob_get_level() > $bootstrapBufferLevel) {
        ob_end_clean();
    }
    error_log('Class Archive identity derivative fallback failed: ' . get_class($exception));
    classArchiveIdentityDerivativeDeny(503);
}
