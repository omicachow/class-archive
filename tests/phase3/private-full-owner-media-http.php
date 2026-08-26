<?php

declare(strict_types=1);

/**
 * Read-only, owner-runtime deployment probe for MediaGuard.
 *
 * The synthetic Phase 0 suite remains the complete role/era matrix. This
 * probe complements it with two actual managed originals from the private
 * full library: it derives one real source and one real derivative entirely
 * inside the Piwigo container, then proves that a guest knowing either URL is
 * denied for GET, HEAD and Range. It never prints an id, path, filename,
 * checksum, source collection, or response body.
 */

$assertions = 0;
$transactionStarted = false;

function privateFullOwnerMediaHttpFail(string $code): never
{
    throw new RuntimeException($code);
}

function privateFullOwnerMediaHttpAssert(bool $condition, string $code): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        privateFullOwnerMediaHttpFail($code);
    }
}

function privateFullOwnerMediaHttpTable(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) {
        privateFullOwnerMediaHttpFail('table_identifier_invalid');
    }
    return '`' . $name . '`';
}

function privateFullOwnerMediaHttpEncodePath(string $relative): string
{
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '?') || str_contains($relative, '#')) {
        privateFullOwnerMediaHttpFail('managed_path_invalid');
    }
    $segments = explode('/', $relative);
    foreach ($segments as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') {
            privateFullOwnerMediaHttpFail('managed_path_invalid');
        }
    }
    return '/' . implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
}

function privateFullOwnerMediaHttpStatus(string $mode, string $uri): int
{
    if (!in_array($mode, ['GET', 'HEAD', 'RANGE'], true)
        || !str_starts_with($uri, '/')
        || str_contains($uri, "\r")
        || str_contains($uri, "\n")
        || strlen($uri) > 8192) {
        privateFullOwnerMediaHttpFail('http_request_invalid');
    }

    $errno = 0;
    $error = '';
    $socket = @stream_socket_client('tcp://127.0.0.1:80', $errno, $error, 5, STREAM_CLIENT_CONNECT);
    if (!is_resource($socket)) {
        privateFullOwnerMediaHttpFail('loopback_connect_failed');
    }

    try {
        stream_set_timeout($socket, 5);
        $method = $mode === 'RANGE' ? 'GET' : $mode;
        $request = $method . ' ' . $uri . " HTTP/1.1\r\n"
            . "Host: 127.0.0.1\r\n"
            . "Accept: */*\r\n"
            . "Connection: close\r\n";
        if ($mode === 'RANGE') {
            $request .= "Range: bytes=0-31\r\n";
        }
        $request .= "\r\n";
        if (@fwrite($socket, $request) !== strlen($request)) {
            privateFullOwnerMediaHttpFail('loopback_write_failed');
        }

        $statusLine = @fgets($socket, 1024);
        if (!is_string($statusLine) || preg_match('/\AHTTP\/1\.[01] ([1-5][0-9]{2})\b/', $statusLine, $matches) !== 1) {
            privateFullOwnerMediaHttpFail('loopback_response_invalid');
        }
        $headerBytes = strlen($statusLine);
        while (($line = @fgets($socket, 4096)) !== false) {
            $headerBytes += strlen($line);
            if ($headerBytes > 32768) {
                privateFullOwnerMediaHttpFail('loopback_headers_too_large');
            }
            if ($line === "\r\n" || $line === "\n") {
                break;
            }
        }
        // Deliberately do not read a response body. Any unexpected 2xx/3xx
        // status is rejected from headers alone, so an ACL regression cannot
        // make this attestation process stream a private original.
        return (int) $matches[1];
    } finally {
        fclose($socket);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDOUT, "PRIVATE_FULL_OWNER_MEDIA_HTTP=FAIL code=cli_posix_required assertions=0\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDOUT, "PRIVATE_FULL_OWNER_MEDIA_HTTP=FAIL code=nginx_user_required assertions=0\n");
    exit(1);
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'
    || getenv('CLASS_ARCHIVE_PRIVATE_FULL_OWNER_MEDIA_HTTP') !== '1') {
    fwrite(STDOUT, "PRIVATE_FULL_OWNER_MEDIA_HTTP=FAIL code=private_full_owner_gate_required assertions=0\n");
    exit(1);
}

try {
    $root = '/var/www/html/piwigo';
    if (realpath($root) !== $root || is_link($root)) {
        privateFullOwnerMediaHttpFail('piwigo_root_untrusted');
    }
    chdir($root) || privateFullOwnerMediaHttpFail('piwigo_root_unavailable');
    // Piwigo's CLI bootstrap still consults REMOTE_ADDR while constructing
    // the session. Keep the probe deterministic and loopback-only instead of
    // allowing an unset CLI server variable to abort before MediaGuard runs.
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    define('PHPWG_ROOT_PATH', './');
    ob_start();
    require PHPWG_ROOT_PATH . 'include/common.inc.php';
    ob_end_clean();
    require_once PHPWG_ROOT_PATH . 'plugins/ClassArchivePolicy/src/MediaGuard.php';

    global $mysqli, $prefixeTable;
    if (!$mysqli instanceof mysqli || !is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        privateFullOwnerMediaHttpFail('database_unavailable');
    }
    if (!$mysqli->set_charset('utf8mb4') || !$mysqli->query('START TRANSACTION READ ONLY')) {
        privateFullOwnerMediaHttpFail('read_only_transaction_unavailable');
    }
    $transactionStarted = true;

    $photoTable = privateFullOwnerMediaHttpTable($prefixeTable . 'class_identity_photo');
    $archiveTable = privateFullOwnerMediaHttpTable($prefixeTable . 'class_identity_archive_image');
    $result = $mysqli->query(
        'SELECT p.`piwigo_image_id` FROM ' . $photoTable . ' p '
        . 'INNER JOIN ' . $archiveTable . ' a ON a.`piwigo_image_id`=p.`piwigo_image_id` '
        . "WHERE p.`state`='ACTIVE' AND a.`era`='HERITAGE' ORDER BY p.`piwigo_image_id` ASC LIMIT 1"
    );
    if (!$result instanceof mysqli_result) {
        privateFullOwnerMediaHttpFail('representative_query_failed');
    }
    try {
        $row = $result->fetch_assoc();
    } finally {
        $result->free();
    }
    $imageId = is_array($row) ? (int) ($row['piwigo_image_id'] ?? 0) : 0;
    privateFullOwnerMediaHttpAssert($imageId > 0, 'representative_photo_missing');

    $original = ClassArchiveMediaGuard::resolveCanonicalDelivery($imageId, 'original');
    $thumbnail = ClassArchiveMediaGuard::resolveCanonicalDelivery($imageId, 'thumbnail');
    $sourcePath = (string) ($original['request']->sourcePath ?? '');
    $derivativePath = (string) ($thumbnail['request']->derivativePath ?? '');
    privateFullOwnerMediaHttpAssert(
        str_starts_with($sourcePath, 'upload/') || str_starts_with($sourcePath, 'galleries/'),
        'representative_source_invalid',
    );
    privateFullOwnerMediaHttpAssert($derivativePath !== '', 'representative_derivative_invalid');
    ClassArchiveMediaGuard::assertDeliveryTarget($thumbnail['request']);

    $surfaces = [
        'ORIGINAL' => privateFullOwnerMediaHttpEncodePath($sourcePath),
        'DERIVATIVE' => '/_data/i' . privateFullOwnerMediaHttpEncodePath($derivativePath),
    ];
    $requestCount = 0;
    foreach ($surfaces as $surface => $uri) {
        foreach (['GET', 'HEAD', 'RANGE'] as $mode) {
            $status = privateFullOwnerMediaHttpStatus($mode, $uri);
            ++$requestCount;
            privateFullOwnerMediaHttpAssert($status === 403, strtolower($surface) . '_' . strtolower($mode) . '_guest_not_denied');
        }
    }

    fwrite(
        STDOUT,
        'PRIVATE_FULL_OWNER_MEDIA_HTTP=PASS assertions=' . $assertions
            . ' direct_guest_requests=' . $requestCount
            . " methods=GET_HEAD_RANGE surfaces=ORIGINAL_DERIVATIVE scope=OWNER_8190\n",
    );
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected_runtime_failure';
    }
    fwrite(STDOUT, 'PRIVATE_FULL_OWNER_MEDIA_HTTP=FAIL code=' . $code . ' assertions=' . $assertions . "\n");
    exit(1);
} finally {
    if (isset($mysqli) && $mysqli instanceof mysqli) {
        if ($transactionStarted) {
            $mysqli->rollback();
        }
    }
}
