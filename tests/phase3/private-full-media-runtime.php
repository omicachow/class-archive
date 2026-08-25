<?php

declare(strict_types=1);

/**
 * Read-only, aggregate verification of private full-library managed originals.
 *
 * This is deliberately run inside the candidate Piwigo container as nginx. It
 * never opens the source roots, staging tree, or private manifest, and it
 * never emits filenames, managed paths, hashes, or identifiers. Its purpose is
 * to prove that every imported canonical original remains a private 0660 file
 * and that a deterministic, evenly distributed checksum sample still matches
 * the Class Archive checksum recorded at import. Full byte verification is
 * deliberately performed by the resumable importer before publication; doing
 * it again for every owner-open would turn this acceptance gate into an
 * unnecessary multi-gigabyte read.
 */

$assertions = 0;
$database = null;
$transactionStarted = false;
const PRIVATE_FULL_MEDIA_CHECKSUM_SAMPLE = 64;

function privateFullMediaFail(string $code): never
{
    throw new RuntimeException($code);
}

function privateFullMediaAssert(bool $condition, string $code): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        privateFullMediaFail($code);
    }
}

function privateFullMediaTable(string $name): string
{
    if (preg_match('/\A[A-Za-z0-9_]{1,64}\z/D', $name) !== 1) {
        privateFullMediaFail('table_identifier_invalid');
    }
    return '`' . $name . '`';
}

/** @param list<mixed> $parameters @return list<array<string,mixed>> */
function privateFullMediaRows(mysqli $database, string $sql, array $parameters = []): array
{
    $statement = $database->prepare($sql);
    if (!$statement instanceof mysqli_stmt) {
        privateFullMediaFail('database_prepare_failed');
    }
    try {
        if (!$statement->execute($parameters)) {
            privateFullMediaFail('database_execute_failed');
        }
        $result = $statement->get_result();
        if (!$result instanceof mysqli_result) {
            privateFullMediaFail('database_result_failed');
        }
        try {
            return $result->fetch_all(MYSQLI_ASSOC);
        } finally {
            $result->free();
        }
    } finally {
        $statement->close();
    }
}

/** @param list<mixed> $parameters @return array<string,mixed>|null */
function privateFullMediaRow(mysqli $database, string $sql, array $parameters = []): ?array
{
    $rows = privateFullMediaRows($database, $sql, $parameters);
    if (count($rows) > 1) {
        privateFullMediaFail('database_row_ambiguous');
    }
    return $rows[0] ?? null;
}

function privateFullMediaContainedPath(string $relative): string
{
    if (!str_starts_with($relative, './upload/') || str_contains($relative, "\0")) {
        privateFullMediaFail('managed_reference_invalid');
    }
    $root = realpath('/var/www/html/piwigo/upload');
    $resolved = realpath('/var/www/html/piwigo' . substr($relative, 1));
    if ($root === false || $resolved === false || !str_starts_with($resolved, $root . DIRECTORY_SEPARATOR)) {
        privateFullMediaFail('managed_path_invalid');
    }
    return $resolved;
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDOUT, "PRIVATE_FULL_MEDIA_RUNTIME=FAIL code=cli_posix_required assertions=0\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDOUT, "PRIVATE_FULL_MEDIA_RUNTIME=FAIL code=nginx_user_required assertions=0\n");
    exit(1);
}
if (getenv('CLASS_ARCHIVE_PRIVATE_REAL_FULL') !== '1'
    || getenv('CLASS_ARCHIVE_PRIVATE_FULL_MEDIA_FIXTURE') !== '1'
) {
    fwrite(STDOUT, "PRIVATE_FULL_MEDIA_RUNTIME=FAIL code=private_full_fixture_gate_required assertions=0\n");
    exit(1);
}

try {
    define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
    $conf = [];
    $prefixeTable = null;
    require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
    if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
        privateFullMediaFail('database_prefix_invalid');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $database = @new mysqli(
        (string) ($conf['db_host'] ?? ''),
        (string) ($conf['db_user'] ?? ''),
        (string) ($conf['db_password'] ?? ''),
        (string) ($conf['db_base'] ?? ''),
    );
    if ($database->connect_errno !== 0 || !$database->set_charset('utf8mb4')) {
        privateFullMediaFail('database_unavailable');
    }
    if (!$database->query('START TRANSACTION READ ONLY')) {
        privateFullMediaFail('read_only_transaction_unavailable');
    }
    $transactionStarted = true;

    $ci = $prefixeTable . 'class_identity_';
    $tables = [
        'images' => privateFullMediaTable($prefixeTable . 'images'),
        'photo' => privateFullMediaTable($ci . 'photo'),
        'archive' => privateFullMediaTable($ci . 'archive_image'),
        'import' => privateFullMediaTable($ci . 'private_library_import'),
        'item' => privateFullMediaTable($ci . 'private_library_import_item'),
    ];

    $import = privateFullMediaRow(
        $database,
        'SELECT `import_id`,`item_total`,`applied_count`,`deduplicated_count`,`failed_count`,`state` FROM '
            . $tables['import'] . " WHERE `state`='COMPLETED' ORDER BY `completed_at` DESC LIMIT 1",
    );
    privateFullMediaAssert($import !== null, 'completed_import_missing');
    privateFullMediaAssert(
        is_string($import['import_id'] ?? null)
            && strlen((string) $import['import_id']) === 16
            && (int) ($import['item_total'] ?? 0) > 0
            && (int) ($import['failed_count'] ?? -1) === 0
            && (int) ($import['applied_count'] ?? -1) + (int) ($import['deduplicated_count'] ?? -1)
                === (int) ($import['item_total'] ?? -1),
        'completed_import_counts_invalid',
    );
    $importId = (string) $import['import_id'];

    $unresolved = privateFullMediaRow(
        $database,
        'SELECT COUNT(*) AS `total` FROM ' . $tables['item']
            . " WHERE `import_id`=? AND `state` NOT IN ('APPLIED','DEDUPLICATED')",
        [$importId],
    );
    privateFullMediaAssert((int) ($unresolved['total'] ?? -1) === 0, 'import_items_not_complete');

    $rows = privateFullMediaRows(
        $database,
        'SELECT i.`id`,i.`path`,p.`media_checksum`,p.`state`,a.`era` FROM ' . $tables['images'] . ' i '
            . 'INNER JOIN ' . $tables['photo'] . ' p ON p.`piwigo_image_id`=i.`id` '
            . 'INNER JOIN ' . $tables['archive'] . ' a ON a.`piwigo_image_id`=i.`id` '
            . "WHERE p.`state`='ACTIVE' AND a.`era`='HERITAGE' ORDER BY i.`id` ASC",
    );
    $expectedCanonical = (int) ($import['applied_count'] ?? -1);
    privateFullMediaAssert($expectedCanonical > 0 && count($rows) === $expectedCanonical, 'canonical_photo_count_invalid');

    $imageCount = privateFullMediaRow($database, 'SELECT COUNT(*) AS `total` FROM ' . $tables['images']);
    privateFullMediaAssert((int) ($imageCount['total'] ?? -1) === $expectedCanonical, 'cross_environment_image_contamination');

    $seenReferences = [];
    $modeVerified = 0;
    $checksumVerified = 0;
    $sampleStride = max(1, (int) floor(count($rows) / PRIVATE_FULL_MEDIA_CHECKSUM_SAMPLE));
    foreach ($rows as $offset => $row) {
        privateFullMediaAssert(
            (int) ($row['id'] ?? 0) > 0
                && is_string($row['path'] ?? null)
                && is_string($row['media_checksum'] ?? null)
                && strlen((string) $row['media_checksum']) === 32
                && ($row['state'] ?? null) === 'ACTIVE'
                && ($row['era'] ?? null) === 'HERITAGE',
            'canonical_photo_shape_invalid',
        );
        $resolved = privateFullMediaContainedPath((string) $row['path']);
        privateFullMediaAssert(!is_link($resolved) && !isset($seenReferences[$resolved]), 'managed_original_reference_invalid');
        $seenReferences[$resolved] = true;
        $stat = @stat($resolved);
        privateFullMediaAssert(
            is_array($stat)
                && ((int) ($stat['mode'] ?? 0) & 0777) === 0660
                && (int) ($stat['nlink'] ?? 0) === 1,
            'managed_original_mode_or_link_invalid',
        );
        ++$modeVerified;
        if ($checksumVerified < PRIVATE_FULL_MEDIA_CHECKSUM_SAMPLE && $offset % $sampleStride === 0) {
            $checksum = @hash_file('sha256', $resolved);
            privateFullMediaAssert(is_string($checksum) && hash_equals((string) $row['media_checksum'], hex2bin($checksum) ?: ''), 'managed_original_checksum_invalid');
            ++$checksumVerified;
        }
    }

    fwrite(
        STDOUT,
        'PRIVATE_FULL_MEDIA_RUNTIME=PASS assertions=' . $assertions
            . ' originals=' . count($rows)
            . ' mode_0660_verified=' . $modeVerified
            . ' checksum_sampled=' . $checksumVerified
            . " managed_reference_mode=CANONICAL_ONLY\n",
    );
} catch (Throwable $error) {
    $code = $error->getMessage();
    if (preg_match('/\A[a-z0-9_]{1,96}\z/D', $code) !== 1) {
        $code = 'unexpected_runtime_failure';
    }
    fwrite(STDOUT, 'PRIVATE_FULL_MEDIA_RUNTIME=FAIL code=' . $code . ' assertions=' . $assertions . "\n");
    exit(1);
} finally {
    if ($database instanceof mysqli) {
        if ($transactionStarted) {
            $database->rollback();
        }
        $database->close();
    }
}
