<?php

declare(strict_types=1);

if (($_SERVER['CLASS_ARCHIVE_DERIVATIVE_GENERATOR'] ?? null) !== '1') {
    http_response_code(404);
    exit;
}

$relative = rawurldecode((string) ($_SERVER['CLASS_ARCHIVE_DERIVATIVE_PATH'] ?? ''));
if (
    $relative === ''
    || strlen($relative) > 4096
    || str_contains($relative, "\0")
    || str_contains($relative, '\\')
    || str_contains($relative, '//')
    || !preg_match('#\A(?:upload|galleries)/(?:[^/]+/)*[^/]+\z#D', $relative)
) {
    http_response_code(404);
    exit;
}
foreach (explode('/', $relative) as $segment) {
    if ($segment === '' || $segment === '.' || $segment === '..' || strlen($segment) > 255) {
        http_response_code(404);
        exit;
    }
}

$derivativeRoot = '/var/www/html/piwigo/_data/i';
$candidate = $derivativeRoot . '/' . $relative;
register_shutdown_function(static function () use ($candidate, $derivativeRoot): void {
    if (!is_file($candidate) || is_link($candidate)) {
        return;
    }
    $root = realpath($derivativeRoot);
    $resolved = realpath($candidate);
    if (
        $root === false
        || $resolved === false
        || !str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
    ) {
        return;
    }
    // Core i.php writes cached derivatives as 0644. Tighten that cache file
    // after Core finishes without patching Core or replacing its image pipeline.
    @chmod($resolved, 0660);
});

chdir('/var/www/html/piwigo') || exit(1);
require '/var/www/html/piwigo/i.php';
