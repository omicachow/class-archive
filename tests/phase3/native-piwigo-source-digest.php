<?php

declare(strict_types=1);

/**
 * Local regression diagnostic for native Piwigo writes.
 *
 * It emits only SHA-256 digests keyed by schema field; no path, filename,
 * category label, identifier, credential, or image data is serialized.
 */

function nativeSourceDigestRows(mysqli $db, string $sql, array $keyFields, array $valueFields): array
{
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('native_source_digest_query_failed');
    }
    $contexts = [];
    foreach ($valueFields as $field) {
        $contexts[$field] = hash_init('sha256');
    }
    $count = 0;
    try {
        while ($row = $result->fetch_assoc()) {
            $key = implode("\0", array_map(static fn(string $field): string => (string) ($row[$field] ?? ''), $keyFields));
            foreach ($valueFields as $field) {
                $value = $row[$field] === null ? "\xff" : "\x00" . (string) $row[$field];
                hash_update($contexts[$field], pack('N', strlen($key)) . $key . pack('N', strlen($value)) . $value);
            }
            ++$count;
        }
    } finally {
        $result->free();
    }
    $digests = [];
    foreach ($contexts as $field => $context) {
        $digests[$field] = hash_final($context);
    }
    return ['count' => $count, 'fields' => $digests];
}

try {
    if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() === 0) {
        throw new RuntimeException('native_source_digest_unprivileged_cli_required');
    }
    define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
    $conf = [];
    $prefixeTable = null;
    require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        throw new RuntimeException('native_source_digest_prefix_invalid');
    }
    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
        throw new RuntimeException('native_source_digest_database_unavailable');
    }
    try {
        $images = [
            'file', 'date_available', 'date_creation', 'name', 'comment', 'author',
            'filesize', 'width', 'height', 'coi', 'representative_ext',
            'date_metadata_update', 'path', 'storage_category_id', 'level',
            'md5sum', 'added_by', 'rotation', 'latitude', 'longitude',
        ];
        $categories = [
            'name', 'id_uppercat', 'comment', 'dir', 'rank', 'status', 'site_id',
            'visible', 'representative_picture_id', 'uppercats', 'commentable',
            'global_rank', 'image_order', 'permalink', 'lastmodified',
        ];
        $payload = [
            'version' => 1,
            'images' => nativeSourceDigestRows(
                $db,
                'SELECT `id`,`' . implode('`,`', $images) . '` FROM `' . $prefixeTable . 'images` ORDER BY `id`',
                ['id'],
                $images,
            ),
            'image_category' => nativeSourceDigestRows(
                $db,
                'SELECT `image_id`,`category_id`,`rank` FROM `' . $prefixeTable . 'image_category` ORDER BY `image_id`,`category_id`',
                ['image_id', 'category_id'],
                ['rank'],
            ),
            'categories' => nativeSourceDigestRows(
                $db,
                'SELECT `id`,`' . implode('`,`', $categories) . '` FROM `' . $prefixeTable . 'categories` ORDER BY `id`',
                ['id'],
                $categories,
            ),
        ];
    } finally {
        $db->close();
    }
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'NATIVE_SOURCE_DIGEST=FAIL code=' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $error->getMessage()) . "\n");
    exit(1);
}
