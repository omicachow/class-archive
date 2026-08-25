<?php

declare(strict_types=1);

/**
 * Locked MariaDB semantic gate for the private full-library import journal.
 *
 * It invokes migrations 13 and 14 beneath a disposable prefix. No source media,
 * private manifests, or live ClassIdentity rows are read or changed.
 */

const PRIVATE_FULL_SCHEMA_SUFFIXES = [
    'private_library_import_item', 'private_library_import', 'private_library_folder',
    'private_library_collection', 'photo_source', 'album', 'photo', 'principal',
];

const PRIVATE_FULL_SCHEMA_DIGEST_SUFFIXES = [
    'private_library_collection', 'private_library_folder',
    'private_library_import', 'private_library_import_item',
];

function privateFullSchemaIdentifier(string $identifier): string
{
    if (preg_match('/\A[A-Za-z0-9_]+\z/D', $identifier) !== 1) {
        throw new RuntimeException('private_full_schema_identifier_invalid');
    }
    return '`' . $identifier . '`';
}

function privateFullSchemaExecute(mysqli $db, string $sql): void
{
    if ($db->query($sql) === false) {
        throw new RuntimeException('private_full_schema_query_failed_' . $db->errno);
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || !function_exists('posix_getpwuid')) {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_SCHEMA=FAIL reason=cli_posix_required\n");
    exit(1);
}
$runtimeAccount = posix_getpwuid(posix_geteuid());
if (posix_geteuid() === 0 || !is_array($runtimeAccount) || ($runtimeAccount['name'] ?? null) !== 'nginx') {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_SCHEMA=FAIL reason=nginx_user_required\n");
    exit(1);
}

define('PHPWG_ROOT_PATH', '/var/www/html/piwigo/');
$conf = [];
$prefixeTable = null;
require PHPWG_ROOT_PATH . 'local/config/database.inc.php';
if (!is_string($prefixeTable) || preg_match('/\A[A-Za-z0-9_]+\z/D', $prefixeTable) !== 1) {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_SCHEMA=FAIL reason=piwigo_prefix_invalid\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli(
    (string) ($conf['db_host'] ?? ''),
    (string) ($conf['db_user'] ?? ''),
    (string) ($conf['db_password'] ?? ''),
    (string) ($conf['db_base'] ?? ''),
);
if ($db->connect_errno !== 0 || !$db->set_charset('utf8mb4')) {
    fwrite(STDERR, "PRIVATE_FULL_LIBRARY_SCHEMA=FAIL reason=database_unavailable\n");
    exit(1);
}

require dirname(__DIR__, 2) . '/plugins/ClassIdentity/src/Schema.php';

$derive = in_array('--derive', array_slice($_SERVER['argv'], 1), true);
$run = strtolower(bin2hex(random_bytes(6)));
$basePrefix = 'ci_pf_' . $run . '_';
$tablePrefix = $basePrefix . 'class_identity_';
$schema = new ClassIdentity\Schema($db, $basePrefix, '0.1.0');
$exit = 0;

try {
    $versionResult = $db->query('SELECT VERSION()');
    $version = $versionResult instanceof mysqli_result ? (string) ($versionResult->fetch_row()[0] ?? '') : '';
    if ($versionResult instanceof mysqli_result) {
        $versionResult->free();
    }
    if (!str_starts_with($version, '11.8.8-MariaDB')) {
        throw new RuntimeException('private_full_schema_locked_mariadb_required');
    }
    $principal = privateFullSchemaIdentifier($tablePrefix . 'principal');
    $photo = privateFullSchemaIdentifier($tablePrefix . 'photo');
    $album = privateFullSchemaIdentifier($tablePrefix . 'album');
    $photoSource = privateFullSchemaIdentifier($tablePrefix . 'photo_source');
    privateFullSchemaExecute($db, "CREATE TABLE {$principal} (`id` BIGINT UNSIGNED NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    privateFullSchemaExecute($db, "CREATE TABLE {$photo} (`class_photo_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_photo_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    privateFullSchemaExecute($db, "CREATE TABLE {$album} (`class_album_id` BINARY(16) NOT NULL, PRIMARY KEY (`class_album_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    privateFullSchemaExecute($db, "CREATE TABLE {$photoSource} (`source_kind` VARCHAR(24) NOT NULL, CONSTRAINT `chk_ci_photo_source_kind` CHECK (`source_kind` IN ('SUBMISSION','PIWIGO_IMPORT','PRIVATE_QA','MIGRATION','OTHER'))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    (new ReflectionMethod(ClassIdentity\Schema::class, 'migrationPrivateFullLibraryImport'))->invoke($schema);
    (new ReflectionMethod(ClassIdentity\Schema::class, 'migrationPrivateFullNativeCheckpointRecovery'))->invoke($schema);
    $check = $db->query("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c INNER JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='" . $db->real_escape_string($tablePrefix . 'photo_source') . "' AND t.CONSTRAINT_NAME='chk_ci_photo_source_kind' LIMIT 1");
    $checkClause = $check instanceof mysqli_result ? (string) (($check->fetch_assoc()['CHECK_CLAUSE'] ?? '')) : '';
    if ($check instanceof mysqli_result) {
        $check->free();
    }
    if (!str_contains(strtoupper($checkClause), 'PRIVATE_FULL')) {
        throw new RuntimeException('private_full_schema_source_kind_not_migrated');
    }
    $itemTarget = $db->query("SELECT c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c INNER JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE t.CONSTRAINT_SCHEMA=DATABASE() AND t.TABLE_NAME='" . $db->real_escape_string($tablePrefix . 'private_library_import_item') . "' AND t.CONSTRAINT_NAME='chk_ci_private_library_item_target' LIMIT 1");
    $itemTargetClauses = $itemTarget instanceof mysqli_result ? array_column($itemTarget->fetch_all(MYSQLI_ASSOC), 'CHECK_CLAUSE') : [];
    if ($itemTarget instanceof mysqli_result) {
        $itemTarget->free();
    }
    $checkpointClausePresent = false;
    foreach ($itemTargetClauses as $itemTargetClause) {
        $normalizedItemTarget = preg_replace('/[^A-Za-z]/', '', strtoupper((string) $itemTargetClause)) ?? '';
        if (str_contains($normalizedItemTarget, 'STATEINPROCESSINGFAILEDANDCLASSPHOTOIDISNULL')
            && !str_contains($normalizedItemTarget, 'STATEINPENDINGPROCESSINGFAILEDANDCLASSPHOTOIDISNULLANDPIWIGOIMAGEIDISNULL')) {
            $checkpointClausePresent = true;
            break;
        }
    }
    if (!$checkpointClausePresent) {
        throw new RuntimeException('private_full_schema_native_checkpoint_not_resumable');
    }
    $digest = new ReflectionMethod(ClassIdentity\Schema::class, 'semanticDigest');
    $expectedMethod = new ReflectionMethod(ClassIdentity\Schema::class, 'expectedSemanticDigests');
    $expected = $expectedMethod->invoke(null);
    $values = [];
    foreach (PRIVATE_FULL_SCHEMA_DIGEST_SUFFIXES as $suffix) {
        $actual = $digest->invoke($schema, $suffix);
        if (!is_string($actual) || preg_match('/\A[0-9a-f]{64}\z/D', $actual) !== 1) {
            throw new RuntimeException('private_full_schema_digest_invalid_' . $suffix);
        }
        if (!$derive && (!is_array($expected) || !hash_equals((string) ($expected[$suffix] ?? ''), $actual))) {
            throw new RuntimeException('private_full_schema_digest_mismatch_' . $suffix);
        }
        $values[$suffix] = $actual;
    }
    // The important privacy invariant is enforced in DDL rather than inferred
    // from a comment: there is no raw path/file-name VARCHAR column anywhere
    // in the durable import journal.
    $columns = $db->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '" . $db->real_escape_string($tablePrefix) . "private_library_%' AND (COLUMN_NAME LIKE '%path%' OR COLUMN_NAME LIKE '%filename%')");
    $names = $columns instanceof mysqli_result ? array_column($columns->fetch_all(MYSQLI_ASSOC), 'COLUMN_NAME') : [];
    if ($columns instanceof mysqli_result) {
        $columns->free();
    }
    foreach ($names as $name) {
        if (!in_array($name, ['relative_path_digest', 'source_reference_digest', 'original_filename_digest'], true)) {
            throw new RuntimeException('private_full_schema_raw_path_column_detected');
        }
    }
    $status = $derive ? 'DERIVED' : 'PASS';
    fwrite(STDOUT, 'PRIVATE_FULL_LIBRARY_SCHEMA=' . $status . ' collection=' . $values['private_library_collection']
        . ' folder=' . $values['private_library_folder'] . ' import=' . $values['private_library_import']
        . ' item=' . $values['private_library_import_item'] . ' run=' . $run . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'PRIVATE_FULL_LIBRARY_SCHEMA=FAIL run=' . $run . ' reason=' . $error->getMessage() . "\n");
    $exit = 1;
} finally {
    $db->query('SET FOREIGN_KEY_CHECKS = 0');
    foreach (PRIVATE_FULL_SCHEMA_SUFFIXES as $suffix) {
        $db->query('DROP TABLE IF EXISTS ' . privateFullSchemaIdentifier($tablePrefix . $suffix));
    }
    $db->query('SET FOREIGN_KEY_CHECKS = 1');
    $like = $db->real_escape_string($tablePrefix) . '%';
    $remaining = $db->query("SELECT COUNT(*) AS `count` FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE '{$like}'");
    $remainingCount = $remaining instanceof mysqli_result ? (int) (($remaining->fetch_assoc()['count'] ?? -1)) : -1;
    if ($remaining instanceof mysqli_result) {
        $remaining->free();
    }
    if ($remainingCount !== 0) {
        fwrite(STDERR, 'PRIVATE_FULL_LIBRARY_SCHEMA_CLEANUP=FAIL run=' . $run . ' remaining=' . $remainingCount . "\n");
        $exit = 1;
    }
    $db->close();
}

exit($exit);
